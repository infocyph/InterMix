<?php

declare(strict_types=1);

namespace Infocyph\InterMix\Cache\Adapter;

use Countable;
use Memcached;
use Psr\Cache\CacheItemInterface;
use Psr\Cache\CacheItemPoolInterface;
use Infocyph\InterMix\Cache\Item\MemCacheItem;
use Infocyph\InterMix\Serializer\ValueSerializer;
use Infocyph\InterMix\Exceptions\CacheInvalidArgumentException;
use RuntimeException;

class MemCacheAdapter implements CacheItemPoolInterface, Countable
{
    private readonly Memcached $mc;
    private readonly string $ns;
    private array $deferred = [];
    private array $knownKeys = [];

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

    private function map(string $key): string
    {
        return $this->ns . ':' . $key;
    }

    public function multiFetch(array $keys): array
    {
        $prefixed = array_map(fn ($k) => $this->map($k), $keys);
        $raw = $this->mc->getMulti($prefixed, Memcached::GET_PRESERVE_ORDER) ?: [];

        $items = [];
        foreach ($keys as $k) {
            $p = $this->map($k);
            if (isset($raw[$p])) {
                $val = ValueSerializer::unserialize($raw[$p]);
                if ($val instanceof CacheItemInterface) {
                    $val = $val->get();
                }
                $items[$k] = new MemCacheItem($this, $k, $val, true);
            } else {
                $items[$k] = new MemCacheItem($this, $k);
            }
        }
        return $items;
    }

    public function getItem(string $key): MemCacheItem
    {
        $raw = $this->mc->get($this->map($key));
        if ($this->mc->getResultCode() === Memcached::RES_SUCCESS && is_string($raw)) {
            $item = ValueSerializer::unserialize($raw);
            if ($item instanceof MemCacheItem && $item->isHit()) {
                return $item;
            }
        }
        return new MemCacheItem($this, $key);
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

    public function deleteItem(string $key): bool
    {
        $this->mc->delete($this->map($key));
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
        $this->knownKeys = [];
        return true;
    }

    public function save(CacheItemInterface $item): bool
    {
        if (!$item instanceof MemCacheItem) {
            throw new CacheInvalidArgumentException('Wrong item class');
        }
        $blob = ValueSerializer::serialize($item);
        $ttl = $item->ttlSeconds();
        $ok = $this->mc->set($this->map($item->getKey()), $blob, $ttl ?? 0);
        if ($ok) {
            // record key so that count() can be faster if desired
            $this->knownKeys[$item->getKey()] = true;
        }
        return $ok;
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

    private function fetchKeys(): array
    {
        if ($quick = $this->fastKnownKeys()) {
            return $quick;
        }

        $pref = $this->ns . ':';
        if ($keys = $this->keysFromGetAll($pref)) {
            return $keys;
        }
        return $this->keysFromSlabDump($pref);
    }

    private function fastKnownKeys(): array
    {
        return $this->knownKeys ? array_keys($this->knownKeys) : [];
    }

    private function keysFromGetAll(string $pref): array
    {
        $all = $this->mc->getAllKeys();
        if (!is_array($all)) {
            return [];
        }
        return $this->stripNamespace($all, $pref);
    }

    private function keysFromSlabDump(string $pref): array
    {
        $out = [];
        $stats = $this->mc->getStats('items');
        foreach ($stats as $server => $items) {
            foreach ($items as $name => $value) {
                if (!preg_match('/items:(\d+):number/', (string)$name, $m)) {
                    continue;
                }
                $slabId = (int)$m[1];
                $dump = $this->mc->getStats("cachedump $slabId 0");
                if (!isset($dump[$server])) {
                    continue;
                }
                $out = array_merge(
                    $out,
                    $this->stripNamespace(array_keys($dump[$server]), $pref)
                );
            }
        }
        return array_values(array_unique($out));
    }

    private function stripNamespace(array $fullKeys, string $pref): array
    {
        return array_values(array_map(
            fn (string $k) => substr($k, strlen($pref)),
            array_filter($fullKeys, fn (string $k) => str_starts_with($k, $pref))
        ));
    }

    public function count(): int
    {
        return count($this->fetchKeys());
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
     * @param MemCacheItem $i The cache item to persist.
     *
     * @return bool TRUE if the item was successfully persisted, FALSE otherwise.
     */
    public function internalPersist(MemCacheItem $i): bool
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
     * @param MemCacheItem $i The cache item to be queued for deferred saving.
     * @return bool True if the item was successfully queued, false otherwise.
     * @internal
     */
    public function internalQueue(MemCacheItem $i): bool
    {
        return $this->saveDeferred($i);
    }
}
