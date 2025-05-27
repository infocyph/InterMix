<?php

declare(strict_types=1);

namespace Infocyph\InterMix\Cache\Adapter;

use Countable;
use Iterator;
use Memcached;
use RuntimeException;
use Psr\Cache\CacheItemInterface;
use Psr\Cache\CacheItemPoolInterface;
use Infocyph\InterMix\Cache\Item\MemCacheItem;
use Infocyph\InterMix\Serializer\ValueSerializer;
use Infocyph\InterMix\Exceptions\CacheInvalidArgumentException;

/**
 * PSR-6 pool powered by the Memcached extension.
 *
 *  – Uses ValueSerializer so closures/resources survive.
 *  – Deferred queue obeys commit().
 *  – Iterator/Countable enumerate keys via Memcached::getAllKeys()
 *    (requires memcached >= 1.6 and `memcached.use_sasl` OFF).
 */
class MemCacheAdapter implements CacheItemPoolInterface, Iterator, Countable
{
    /* ── state ─────────────────────────────────────────────────────── */
    private readonly Memcached $mc;
    private readonly string $ns;
    private array $deferred = [];      // key => MemCacheItem
    private array $keyList = [];      // iterator snapshot
    private int $pos = 0;

    /**
     * @param string $namespace cache prefix
     * @param array $servers list of [host, port, weight] triples
     *                           defaults to 127.0.0.1:11211
     * @param ?Memcached $client optional pre-configured Memcached instance
     */
    public function __construct(
        string $namespace = 'default',
        array $servers = [['127.0.0.1', 11211, 0]],
        ?Memcached $client = null,
    ) {
        if (!class_exists(Memcached::class)) {
            throw new RuntimeException('Memcached extension not loaded');
        }

        $this->ns = preg_replace('/[^A-Za-z0-9_\-]/', '_', $namespace);
        $this->mc = $client ?? new Memcached();
        if (!$client) {
            $this->mc->addServers($servers);
        }
    }

    /* ── helpers ──────────────────────────────────────────────────── */
    private function k(string $key): string
    {
        $this->validateKey($key);
        return $this->ns . ':' . $key;
    }

    private function validateKey(string $key): void
    {
        if ($key === '' || !preg_match('/^[A-Za-z0-9_.\-]+$/', $key)) {
            throw new CacheInvalidArgumentException(
                'Invalid Memcache key; allowed A-Z, a-z, 0-9, _, ., -',
            );
        }
    }

    /* ── fetch ------------------------------------------------------ */
    public function getItem(string $key): MemCacheItem
    {
        $raw = $this->mc->get($this->k($key));
        if ($this->mc->getResultCode() === MEMCACHED_SUCCESS && is_string($raw)) {
            $item = ValueSerializer::unserialize($raw);
            if ($item instanceof MemCacheItem && $item->isHit()) {
                return $item;
            }
        }
        return new MemCacheItem($this, $key);        // cache miss
    }

    public function getItems(array $keys = []): iterable
    {
        foreach ($keys as $k) {
            yield $k => $this->getItem($k);
        }
    }

    public function hasItem(string $key): bool
    {
        return $this->getItem($key)->isHit();
    }

    /* ── delete / clear -------------------------------------------- */
    public function deleteItem(string $key): bool
    {
        $this->mc->delete($this->k($key));
        return true;
    }

    public function deleteItems(array $keys): bool
    {
        foreach ($keys as $k) {
            $this->deleteItem($k);
        }
        return true;
    }

    public function clear(): bool
    {
        $this->mc->flush();
        $this->deferred = [];
        return true;
    }

    /* ── save / deferred / commit ---------------------------------- */
    public function save(CacheItemInterface $item): bool
    {
        if (!$item instanceof MemCacheItem) {
            throw new CacheInvalidArgumentException('Wrong item class');
        }
        $blob = ValueSerializer::serialize($item);
        $ttl = $item->ttlSeconds();
        return $this->mc->set($this->k($item->getKey()), $blob, $ttl ?? 0);
    }

    public function saveDeferred(CacheItemInterface $item): bool
    {
        if (!$item instanceof MemCacheItem) {
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

    /* ── Iterator & Countable -------------------------------------- */
    private function fetchKeys(): array
    {
        // Warning: getAllKeys() not portable across all memcached servers.
        $raw = $this->mc->getAllKeys() ?: [];
        $pref = $this->ns . ':';
        return array_values(
            array_map(
                fn ($k) => substr((string) $k, strlen($pref)),
                array_filter($raw, fn ($k) => str_starts_with((string) $k, $pref)),
            ),
        );
    }

    public function rewind(): void
    {
        $this->keyList = $this->fetchKeys();
        $this->pos = 0;
    }

    public function current(): mixed
    {
        $item = $this->getItem($this->keyList[$this->pos]);
        return $item->get();
    }

    public function key(): mixed
    {
        return $this->keyList[$this->pos] ?? null;
    }

    public function next(): void
    {
        $this->pos++;
    }

    public function valid(): bool
    {
        return isset($this->keyList[$this->pos]);
    }

    public function count(): int
    {
        return count($this->fetchKeys());
    }

    /* ── internal helpers for item --------------------------------- */
    public function internalPersist(MemCacheItem $i): bool
    {
        return $this->save($i);
    }

    public function internalQueue(MemCacheItem $i): bool
    {
        return $this->saveDeferred($i);
    }
}
