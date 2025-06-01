<?php

declare(strict_types=1);

namespace Infocyph\InterMix\Cache\Adapter;

use Countable;
use Memcached;
use Psr\Cache\InvalidArgumentException;
use RuntimeException;
use Psr\Cache\CacheItemInterface;
use Psr\Cache\CacheItemPoolInterface;
use Infocyph\InterMix\Cache\Item\MemCacheItem;
use Infocyph\InterMix\Serializer\ValueSerializer;
use Infocyph\InterMix\Exceptions\CacheInvalidArgumentException;

class MemCacheAdapter implements CacheItemPoolInterface, Countable
{
    private readonly Memcached $mc;
    private readonly string $ns;
    private array $deferred = [];
    private array $knownKeys = [];

    /**
     * Constructs a MemCacheAdapter instance.
     *
     * @param string $namespace The prefix for cache keys.
     * @param array $servers A list of [host, port, weight] triples to connect
     *     to. If left empty, the adapter will connect to the default server
     *     at 127.0.0.1:11211.
     * @param ?Memcached $client An optional pre-configured Memcached client
     *     instance. If supplied, the adapter will use it instead of creating
     *     a new one.
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


    /**
     * Namespace a key for Memcached storage.
     *
     * @param string $key Unprefixed key.
     * @return string Prefixed key (e.g. "ns:foo").
     */
    private function map(string $key): string
    {
        return $this->ns . ':' . $key;
    }


    /**
     * Fetches multiple cache items by their unique keys.
     *
     * This method retrieves multiple cache items efficiently by leveraging
     * Memcached's `getMulti` function to fetch all items in a single call.
     * Each key is prefixed with the namespace to avoid collisions.
     *
     * @param array $keys List of keys identifying the cache items to retrieve.
     * @return array An associative array of MemCacheItem objects, keyed by the original cache key.
     */
    public function multiFetch(array $keys): array
    {
        $prefixed = array_map(fn ($k) => $this->map($k), $keys);
        $raw      = $this->mc->getMulti($prefixed, Memcached::GET_PRESERVE_ORDER) ?: [];

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

    /**
     * {@inheritdoc}
     *
     * @param string $key The key of the item to retrieve.
     *
     * @return MemCacheItem The retrieved cache item.
     */
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

    /**
     * {@inheritdoc}
     *
     * @param array $keys The keys of the items to retrieve.  If empty, an
     *      empty iterable is returned.
     *
     * @return iterable An iterable of CacheItemInterface objects.
     */
    public function getItems(array $keys = []): iterable
    {
        foreach ($keys as $k) {
            yield $k => $this->getItem($k);
        }
    }

    /**
     * Determines whether a cache item exists for the given key.
     *
     * This method checks if the cache item associated with the specified key
     * is a cache hit, indicating that the item exists in the cache and has not expired.
     *
     * @param string $key The key of the cache item to check.
     * @return bool Returns true if the cache item exists and is a cache hit, false otherwise.
     * @throws InvalidArgumentException
     */
    public function hasItem(string $key): bool
    {
        return $this->getItem($key)->isHit();
    }


    /**
     * {@inheritdoc}
     *
     * @param string $key The key to delete from the cache.
     *
     * @return bool TRUE if the item was successfully deleted, FALSE otherwise.
     */
    public function deleteItem(string $key): bool
    {
        $this->mc->delete($this->map($key));
        return true;
    }

    /**
     * Deletes multiple cache items in a single operation.
     *
     * @param array $keys identifiers of the cache items to delete
     *
     * @return bool true on success; false if any of the items could not be deleted
     * @throws InvalidArgumentException
     */
    public function deleteItems(array $keys): bool
    {
        foreach ($keys as $k) {
            $this->deleteItem($k);
        }
        return true;
    }

    /**
     * Clears all cache items.
     *
     * This method flushes the entire cache, removing all items stored in Memcached.
     * It also clears the deferred queue and the list of known keys.
     *
     * @return bool TRUE if the cache was successfully cleared.
     */
    public function clear(): bool
    {
        $this->mc->flush();
        $this->deferred = [];
        $this->knownKeys  = [];
        return true;
    }

    /**
     * Saves the cache item to the cache.
     *
     * @param CacheItemInterface $item The cache item to save.
     *
     * @return bool TRUE if the item was successfully saved, FALSE otherwise.
     *
     * @throws CacheInvalidArgumentException If the given item is not an instance of MemCacheItem.
     */
    public function save(CacheItemInterface $item): bool
    {
        if (!$item instanceof MemCacheItem) {
            throw new CacheInvalidArgumentException('Wrong item class');
        }
        $blob = ValueSerializer::serialize($item);
        $ttl = $item->ttlSeconds();
        $ok = $this->mc->set($this->map($item->getKey()), $blob, $ttl ?? 0);
        $ok && $this->mc->getResultCode() === Memcached::RES_SUCCESS;
        return $ok;
    }

    /**
     * Defer saving of a cache item until commit() is explicitly called.
     *
     * If the given item is not an instance of MemCacheItem, false is returned.
     * Otherwise, the item is added to the internal deferred queue, and true is returned.
     *
     * @param CacheItemInterface $item The cache item to be deferred.
     *
     * @return bool true on success; false if the item could not be deferred
     */
    public function saveDeferred(CacheItemInterface $item): bool
    {
        if (!$item instanceof MemCacheItem) {
            return false;
        }
        $this->deferred[$item->getKey()] = $item;
        return true;
    }

    /**
     * Commit any deferred cache items.
     *
     * @return bool true if all deferred items were successfully committed.
     */
    public function commit(): bool
    {
        $ok = true;
        foreach ($this->deferred as $k => $it) {
            $ok = $ok && $this->save($it);
            unset($this->deferred[$k]);
        }
        return $ok;
    }

    /**
     * Internal method to fetch a list of known keys.
     *
     * First tries `getAllKeys` (fast, but requires Memcached >= 3.0.0 and
     * `memcached.use_sasl` OFF), then tries `getAll` (slower, but more
     * backwards-compatible), and finally falls back to walking the slabs
     * (very slow, but the last resort).
     *
     * @return array a list of keys known to the cache
     */
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

    /**
     * Returns all known keys from the in-process registry.
     *
     * The registry is populated when {@see save()} or {@see saveDeferred()} are called.
     * The returned array contains all keys that are known to be in the cache.
     *
     * @return string[]
     *      An array of strings where each string is a cache key.
     */
    private function fastKnownKeys(): array
    {
        return $this->knownKeys ? array_keys($this->knownKeys) : [];
    }

    /**
     * Retrieves all keys from Memcached and filters them based on the given namespace prefix.
     *
     * This method uses Memcached::getAllKeys() to obtain the list of all keys stored
     * in the cache. It then filters the keys to only include those that start with
     * the provided namespace prefix and strips the prefix from each of those keys.
     *
     * @param string $pref The namespace prefix to filter and strip from the keys.
     * @return string[] An array of keys without the namespace prefix.
     */
    private function keysFromGetAll(string $pref): array
    {
        $all = $this->mc->getAllKeys();                // false if disabled
        if (!is_array($all)) {
            return [];
        }
        return $this->stripNamespace($all, $pref);
    }

    /**
     * Memcached::getAllKeys() is only available for Memcached >= 3.0.0,
     * and memcached >= 1.6.0 with `memcached.use_sasl` OFF.
     *
     * As a fallback, this method (inefficiently) walks the slabs to build
     * the list of keys. This is a last resort and should only be used if
     * `memcached.use_sasl` is ON.
     *
     * @param string $pref Namespace prefix to strip from the keys.
     * @return array<string> List of keys without namespace prefix.
     */
    private function keysFromSlabDump(string $pref): array
    {
        $out   = [];
        $stats = $this->mc->getStats('items');

        foreach ($stats as $server => $items) {
            foreach ($items as $name => $value) {
                if (!preg_match('/items:(\d+):number/', (string) $name, $m)) {
                    continue;
                }
                $slabId = (int) $m[1];
                $dump   = $this->mc->getStats("cachedump $slabId 0");
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

    /**
     * Filter a list of keys to only those with the given prefix,
     * then strip the prefix from each of those keys.
     *
     * @param string[] $fullKeys
     * @param string $pref
     * @return string[]
     */
    private function stripNamespace(array $fullKeys, string $pref): array
    {
        return array_values(array_map(
            fn (string $k) => substr($k, strlen($pref)),
            array_filter($fullKeys, fn (string $k) => str_starts_with($k, $pref))
        ));
    }

    /**
     * @return int The number of items in the cache.
     */
    public function count(): int
    {
        return count($this->fetchKeys());
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
