<?php

declare(strict_types=1);

namespace Infocyph\InterMix\Cache\Adapter;

use Countable;
use Psr\Cache\CacheItemInterface;
use Psr\Cache\CacheItemPoolInterface;
use Infocyph\InterMix\Cache\Item\RedisCacheItem;
use Infocyph\InterMix\Serializer\ValueSerializer;
use Infocyph\InterMix\Exceptions\CacheInvalidArgumentException;
use Redis;
use RuntimeException;

class RedisCacheAdapter implements CacheItemPoolInterface, Countable
{
    private readonly Redis $redis;
    private readonly string $ns;
    private array $deferred = [];

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

    private function map(string $key): string
    {
        return $this->ns . ':' . $key;
    }

    public function multiFetch(array $keys): array
    {
        if ($keys === []) {
            return [];
        }

        $prefixed = array_map(fn ($k) => $this->map($k), $keys);
        $rawVals = $this->redis->mget($prefixed);

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
        $raw = $this->redis->get($this->map($key));
        if (is_string($raw)) {
            $item = ValueSerializer::unserialize($raw);
            if ($item instanceof RedisCacheItem && $item->isHit()) {
                return $item;
            }
        }
        return new RedisCacheItem($this, $key);
    }

    public function getItems(array $keys = []): iterable
    {
        foreach ($keys as $k) {
            yield $k => $this->getItem($k);
        }
    }

    public function hasItem(string $key): bool
    {
        return $this->redis->exists($this->map($key)) === 1
            && $this->getItem($key)->isHit();
    }

    public function save(CacheItemInterface $item): bool
    {
        if (!$item instanceof RedisCacheItem) {
            throw new CacheInvalidArgumentException('RedisCacheAdapter expects RedisCacheItem');
        }
        $blob = ValueSerializer::serialize($item);
        $ttl = $item->ttlSeconds();
        return $ttl
            ? $this->redis->setex($this->map($item->getKey()), $ttl, $blob)
            : $this->redis->set($this->map($item->getKey()), $blob);
    }

    public function deleteItem(string $key): bool
    {
        return (bool) $this->redis->del($this->map($key));
    }

    public function deleteItems(array $keys): bool
    {
        $full = array_map(fn ($k) => $this->map($k), $keys);
        return $this->redis->del($full) === count($keys);
    }

    public function clear(): bool
    {
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

    public function count(): int
    {
        $iter = null;
        $count = 0;
        while ($keys = $this->redis->scan($iter, $this->ns . ':*', 1000)) {
            $count += count($keys);
        }
        return $count;
    }

    // ───────────────────────────────────────────────
    // Add PSR-16 “get” / “set” here:
    // ───────────────────────────────────────────────

    /**
     * PSR-16: return raw value or null.
     */
    public function get(string $key): mixed
    {
        $item = $this->getItem($key);
        return $item->isHit() ? $item->get() : null;
    }

    /**
     * PSR-16: store value, $ttl seconds.
     */
    public function set(string $key, mixed $value, ?int $ttl = null): bool
    {
        $item = $this->getItem($key);
        $item->set($value)->expiresAfter($ttl);
        return $this->save($item);
    }


    /**
     * @internal
     * Persists a cache item in the cache pool.
     *
     * This method is called by the cache item when it is persisted
     * using the `save()` method. It is not intended to be called
     * directly.
     *
     * @param RedisCacheItem $i The cache item to persist.
     *
     * @return bool TRUE if the item was successfully persisted, FALSE otherwise.
     */
    public function internalPersist(RedisCacheItem $i): bool
    {
        return $this->save($i);
    }

    /**
     * Adds the given cache item to the internal deferred queue.
     *
     * This method enqueues the cache item for later persistence in
     * the cache pool. The item will not be saved immediately, but
     * will be stored when the commit() method is called.
     *
     * @param RedisCacheItem $i The cache item to be queued for deferred saving.
     * @return bool True if the item was successfully queued, false otherwise.
     */
    public function internalQueue(RedisCacheItem $i): bool
    {
        return $this->saveDeferred($i);
    }
}
