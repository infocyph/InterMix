<?php

declare(strict_types=1);

namespace Infocyph\InterMix\Cache\Adapter;

use Countable;
use InvalidArgumentException;
use RuntimeException;
use Psr\Cache\CacheItemInterface;
use Psr\Cache\CacheItemPoolInterface;
use Infocyph\InterMix\Cache\Item\ApcuCacheItem;
use Infocyph\InterMix\Serializer\ValueSerializer;
use Infocyph\InterMix\Exceptions\CacheInvalidArgumentException;

class ApcuCacheAdapter implements CacheItemPoolInterface, Countable
{
    /* ── state ───────────────────────────────────────────────────────── */
    private readonly string $ns;
    private array $deferred = [];

    /**
     * Initializes the APCu adapter.
     *
     * @param string $namespace The cache key prefix (namespace).
     *
     * @throws RuntimeException If the APCu extension is not enabled.
     */
    public function __construct(string $namespace = 'default')
    {
        if (!extension_loaded('apcu') || !apcu_enabled()) {
            throw new RuntimeException('APCu extension is not enabled');
        }
        $this->ns = preg_replace('/[^A-Za-z0-9_\-]/', '_', $namespace);
    }


    /**
     * Prefixes the given key with the namespace.
     *
     * This function maps a user-provided key to a namespaced key
     * by prepending the namespace to ensure uniqueness within the cache.
     *
     * @param string $key The original key to be namespaced.
     * @return string The namespaced key.
     */
    private function map(string $key): string
    {
        return $this->ns . ':' . $key;
    }


    /**
     * PSR-6 multiFetch() method.
     *
     * Fetches multiple cache items by their unique keys.
     *
     * @param array $keys List of keys that uniquely identify the items to retrieve.
     * @return array a list of CacheItemInterface objects keyed by the cache key
     * @throws InvalidArgumentException
     */
    public function multiFetch(array $keys): array
    {
        if ($keys === []) {
            return [];
        }
        $prefixed = array_map(fn ($k) => $this->ns . ':' . $k, $keys);
        $raw = apcu_fetch($prefixed);

        $items = [];
        foreach ($keys as $k) {
            $p = $this->ns . ':' . $k;
            if (array_key_exists($p, $raw)) {
                $val = ValueSerializer::unserialize($raw[$p]);
                if ($val instanceof CacheItemInterface) {
                    $val = $val->get();
                }
                $items[$k] = new ApcuCacheItem($this, $k, $val, true);
            } else {
                $items[$k] = new ApcuCacheItem($this, $k);
            }
        }
        return $items;
    }

    /**
     * Retrieves a cache item by its key.
     *
     * This method attempts to fetch the cache item associated with the given key
     * from the APCu cache. If the item is found and is a cache hit, it is returned.
     * Otherwise, a new ApcuCacheItem is returned indicating a cache miss.
     *
     * @param string $key The key of the cache item to retrieve.
     * @return ApcuCacheItem The cache item associated with the specified key.
     */
    public function getItem(string $key): ApcuCacheItem
    {
        $apcuKey = $this->map($key);
        $success = false;
        $raw = apcu_fetch($apcuKey, $success);

        if ($success && is_string($raw)) {
            $item = ValueSerializer::unserialize($raw);
            if ($item instanceof ApcuCacheItem && $item->isHit()) {
                return $item;
            }
        }
        return new ApcuCacheItem($this, $key); // cache miss
    }

    /**
     * {@inheritDoc}
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
     * Determines whether an item is present in the cache.
     *
     * Note: It is recommended to use this method to check for cache hits
     * instead of using {@see getItem()} and checking for a cache miss (i.e.
     * a null value). This method is more efficient and reliable.
     *
     * @param string $key The key of the cache item to check for.
     *
     * @return bool Returns true if the cache item exists, false otherwise.
     */
    public function hasItem(string $key): bool
    {
        return $this->getItem($key)->isHit();
    }


    /**
     * Deletes a cache item by its key.
     *
     * This method attempts to remove the cache entry associated with the given key
     * from the APCu cache. It returns true if the item was successfully deleted or
     * false if the item could not be deleted (e.g., if it does not exist).
     *
     * @param string $key The key of the cache item to delete.
     *
     * @return bool Returns true on success, false on failure.
     */
    public function deleteItem(string $key): bool
    {
        return apcu_delete($this->map($key));
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
        $ok = true;
        foreach ($keys as $k) {
            $ok = $ok && $this->deleteItem($k);
        }
        return $ok;
    }

    /**
     * Clears all cache items in the current namespace.
     *
     * This method removes all items stored in APCu under the current
     * namespace and clears the deferred queue.
     *
     * @return bool TRUE if the cache was successfully cleared.
     */
    public function clear(): bool
    {
        foreach ($this->listKeys() as $apcuKey) {
            apcu_delete($apcuKey);
        }
        $this->deferred = [];
        return true;
    }


    /**
     * Saves the cache item to APCu.
     *
     * @param CacheItemInterface $item The cache item to save.
     *
     * @return bool TRUE if the item was successfully saved, FALSE otherwise.
     *
     * @throws CacheInvalidArgumentException If the given item is not an instance of ApcuCacheItem.
     */
    public function save(CacheItemInterface $item): bool
    {
        if (!$item instanceof ApcuCacheItem) {
            throw new CacheInvalidArgumentException('Wrong item type for ApcuCacheAdapter');
        }
        $blob = ValueSerializer::serialize($item);
        $ttl = $item->ttlSeconds();
        return apcu_store($this->map($item->getKey()), $blob, $ttl ?? 0);
    }

    /**
     * Defer saving of a cache item until commit() is called.
     *
     * The given cache item is added to the internal deferred queue and
     * will be persisted in the cache pool when the commit() method is
     * called. If the item is not an instance of ApcuCacheItem, false
     * will be returned.
     *
     * @param CacheItemInterface $item The cache item to be deferred.
     * @return bool True if the item was successfully deferred, false otherwise.
     */
    public function saveDeferred(CacheItemInterface $item): bool
    {
        if (!$item instanceof ApcuCacheItem) {
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
     * Retrieves a list of keys stored in the APCu cache for the current namespace.
     *
     * Iterates over the cache entries and filters them based on the current
     * namespace prefix. Only keys that start with the namespace prefix are
     * included in the returned list.
     *
     * @return array
     *      An array of strings where each string is a cache key with the
     *      current namespace prefix.
     */
    private function listKeys(): array
    {
        $info = apcu_cache_info();
        $pref = $this->ns . ':';
        $keys = [];
        foreach ($info['cache_list'] ?? [] as $entry) {
            if (isset($entry['info']) && str_starts_with((string)$entry['info'], $pref)) {
                $keys[] = $entry['info'];
            }
        }
        return $keys;
    }

    /**
     * {@inheritDoc}
     *
     * This is a no-op because APCu doesn't provide a way to get the number of cache entries
     * without scanning all of them.
     */
    public function count(): int
    {
        return count($this->listKeys());
    }

    /**
     * @param ApcuCacheItem $item The cache item to persist.
     *
     * @return bool TRUE if the item was successfully persisted, FALSE otherwise.
     * @internal
     * Persists a cache item in the cache pool.
     *
     * This method is called by the cache item when it is persisted
     * using the `save()` method. It is not intended to be called
     * directly.
     *
     */
    public function internalPersist(ApcuCacheItem $item): bool
    {
        return $this->save($item);
    }

    /**
     * Adds the given cache item to the internal deferred queue.
     *
     * This method enqueues the cache item for later persistence in
     * the cache pool. The item will not be saved immediately, but
     * will be stored when the commit() method is called.
     *
     * @param ApcuCacheItem $item The cache item to be queued for deferred saving.
     * @return bool True if the item was successfully queued, false otherwise.
     */
    public function internalQueue(ApcuCacheItem $item): bool
    {
        return $this->saveDeferred($item);
    }
}
