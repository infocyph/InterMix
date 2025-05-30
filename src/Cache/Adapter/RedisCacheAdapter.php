<?php

declare(strict_types=1);

namespace Infocyph\InterMix\Cache\Adapter;

use Countable;
use Iterator;
use Redis;
use RuntimeException;
use Psr\Cache\CacheItemInterface;
use Psr\Cache\CacheItemPoolInterface;
use Infocyph\InterMix\Cache\Item\RedisCacheItem;
use Infocyph\InterMix\Serializer\ValueSerializer;
use Infocyph\InterMix\Exceptions\CacheInvalidArgumentException;

/**
 * PSR-6 pool backed by phpredis.
 *
 * • Namespaced keys:  "<ns>:<userKey>"
 * • ValueSerializer handles closures/resources
 * • Deferred queue obeys commit()
 * • Iterator & Countable use SCAN (non-blocking)
 */
class RedisCacheAdapter implements CacheItemPoolInterface, Iterator, Countable
{
    /* ── state ─────────────────────────────────────────────────── */
    private readonly Redis $redis;
    private readonly string $ns;
    private array $deferred = [];   // key => RedisCacheItem
    private array $scanBuffer = [];   // iterator
    private ?string $scanCursor = null;

    public function __construct(
        string $namespace = 'default',
        string $dsn = 'redis://127.0.0.1:6379',
        ?Redis $client = null,
    ) {
        if (!class_exists(Redis::class)) {
            throw new RuntimeException('phpredis extension not loaded');
        }

        $this->ns = preg_replace('/[^A-Za-z0-9_\-]/', '_', $namespace);
        $this->redis = $client ?? $this->connect($dsn);
    }

    /* ── connection helper ─────────────────────────────────────── */
    private function connect(string $dsn): Redis
    {
        $r = new Redis();
        $parts = parse_url($dsn);
        if (!$parts) {
            throw new RuntimeException("Invalid Redis DSN: $dsn");
        }
        $host = $parts['host'] ?? '127.0.0.1';
        $port = $parts['port'] ?? 6379;
        $r->connect($host, (int)$port);
        if (isset($parts['pass'])) {
            $r->auth($parts['pass']);
        }
        if (isset($parts['path']) && $parts['path'] !== '/') {
            $db = (int)ltrim($parts['path'], '/');
            $r->select($db);
        }
        return $r;
    }

    /* ── key helpers ───────────────────────────────────────────── */
    private function k(string $key): string
    {
        $this->validateKey($key);
        return $this->ns . ':' . $key;
    }

    private function validateKey(string $key): void
    {
        if ($key === '' || !preg_match('/^[A-Za-z0-9_.\-]+$/', $key)) {
            throw new CacheInvalidArgumentException(
                'Invalid Redis key: allowed A-Z, a-z, 0-9, _, ., -',
            );
        }
    }

    /* ── fetch --------------------------------------------------- */
    public function multiFetch(array $keys): array
    {
        $prefixed = array_map(fn ($k) => $this->ns .':'. $k, $keys);
        $rawVals  = $this->redis->mget($prefixed);   // ordered list

        $items = [];
        foreach ($keys as $idx => $k) {
            $v = $rawVals[$idx];
            if ($v !== null && $v !== false) {
                $val = ValueSerializer::unserialize($v);
                if ($val instanceof CacheItemInterface) {
                    $val = $val->get();
                }
                $items[$k] = new RedisCacheItem($this, $k, $val, true);
            } else {
                $items[$k] = new RedisCacheItem($this, $k);
            }
        }
        return $items;
    }

    public function getItem(string $key): RedisCacheItem
    {
        $raw = $this->redis->get($this->k($key));
        if (is_string($raw)) {
            $item = ValueSerializer::unserialize($raw);
            if ($item instanceof RedisCacheItem && $item->isHit()) {
                return $item;
            }
        }
        return new RedisCacheItem($this, $key); // cache miss
    }

    public function getItems(array $keys = []): iterable
    {
        foreach ($keys as $k) {
            yield $k => $this->getItem($k);
        }
    }

    public function hasItem(string $key): bool
    {
        return $this->redis->exists($this->k($key)) === 1
            && $this->getItem($key)->isHit();
    }

    /* ── save / delete / clear ----------------------------------- */
    public function save(CacheItemInterface $item): bool
    {
        if (!$item instanceof RedisCacheItem) {
            throw new CacheInvalidArgumentException('RedisCacheAdapter expects RedisCacheItem');
        }
        $blob = ValueSerializer::serialize($item);
        $ttl = $item->ttlSeconds();
        return $ttl
            ? $this->redis->setex($this->k($item->getKey()), $ttl, $blob)
            : $this->redis->set($this->k($item->getKey()), $blob);
    }

    public function deleteItem(string $key): bool
    {
        return (bool)$this->redis->del($this->k($key));
    }

    public function deleteItems(array $keys): bool
    {
        $full = array_map(fn ($k) => $this->k($k), $keys);
        return $this->redis->del($full) === count($keys);
    }

    public function clear(): bool
    {
        /* use SCAN to delete only this namespace */
        $cursor = null;
        do {
            $keys = $this->redis->scan($cursor, $this->ns . ':*', 1000);
            if ($keys) {
                $this->redis->del($keys);
            }
        } while ($cursor);
        $this->deferred = [];
        return true;
    }

    /* ── deferred queue ------------------------------------------ */
    public function saveDeferred(CacheItemInterface $item): bool
    {
        if (!$item instanceof RedisCacheItem) {
            return false;
        }
        $this->deferred[$item->getKey()] = $item;
        return true;
    }

    public function commit(): bool
    {
        $ok = true;
        foreach ($this->deferred as $k => $it) {
            $ok = $ok && $this->save($it);
            unset($this->deferred[$k]);
        }
        return $ok;
    }

    /* ── iterator ------------------------------------------------ */
    public function rewind(): void
    {
        $this->scanCursor = null;
        $this->scanBuffer = [];
        $this->next();                     // prefetch first batch
    }

    public function current(): mixed
    {
        $key = $this->key();
        return $key ? $this->getItem($key)->get() : null;
    }

    public function key(): mixed
    {
        return $this->scanBuffer[0] ?? null;
    }

    public function next(): void
    {
        /* pop current */
        if ($this->scanBuffer) {
            array_shift($this->scanBuffer);
        }

        /* refill buffer if empty */
        while (!$this->scanBuffer && ($this->scanCursor !== 0)) {
            $this->scanBuffer = $this->redis->scan(
                $this->scanCursor,
                $this->ns . ':*',
                100,
            ) ?: [];
            /* strip namespace prefix */
            $this->scanBuffer = array_map(
                fn ($k) => substr($k, strlen($this->ns) + 1),
                $this->scanBuffer,
            );
        }
    }

    public function valid(): bool
    {
        return !empty($this->scanBuffer);
    }

    /* ── countable ----------------------------------------------- */
    public function count(): int
    {
        $iter = null;
        $count = 0;
        while ($keys = $this->redis->scan($iter, $this->ns . ':*', 1000)) {
            $count += count($keys);
        }
        return $count;
    }

    /* ── item callbacks ------------------------------------------ */
    public function internalPersist(RedisCacheItem $i): bool
    {
        return $this->save($i);
    }

    public function internalQueue(RedisCacheItem $i): bool
    {
        return $this->saveDeferred($i);
    }
}
