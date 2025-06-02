<?php

declare(strict_types=1);

namespace Infocyph\InterMix\Cache\Adapter;

use Countable;
use RuntimeException;
use Psr\Cache\CacheItemInterface;
use Psr\Cache\CacheItemPoolInterface;
use Infocyph\InterMix\Cache\Item\ApcuCacheItem;
use Infocyph\InterMix\Serializer\ValueSerializer;
use Infocyph\InterMix\Exceptions\CacheInvalidArgumentException;

class ApcuCacheAdapter implements CacheItemPoolInterface, Countable
{
    private readonly string $ns;
    private array $deferred = [];

    /**
     * Constructs a new APCuCacheAdapter instance.
     *
     * Initializes the adapter with a specified namespace. The namespace is cleaned
     * to ensure it contains only valid characters (A-Z, a-z, 0-9, _, -).
     * Throws an exception if the APCu extension is not enabled.
     *
     * @param string $namespace The namespace prefix for keys. Defaults to 'default'.
     * @throws RuntimeException if the APCu extension is not enabled.
     */
    public function __construct(string $namespace = 'default')
    {
        if (!extension_loaded('apcu') || !apcu_enabled()) {
            throw new RuntimeException('APCu extension is not enabled');
        }
        $this->ns = preg_replace('/[^A-Za-z0-9_\-]/', '_', $namespace);
    }

    /**
     * Internal mapping function for APCu keys.
     *
     * @param string $key The key to map.
     * @return string The mapped key.
     * @internal
     */
    private function map(string $key): string
    {
        return $this->ns . ':' . $key;
    }

    /**
     * Retrieves multiple cache items by their keys.
     *
     * This method fetches and returns an array of cache items for the specified keys.
     * If a key does not exist in the cache, an empty cache item is created for that key.
     *
     * @param array<string> $keys The keys of the items to retrieve.
     * @return array<string, ApcuCacheItem> An array of cache items indexed by their keys.
     */
    public function multiFetch(array $keys): array
    {
        if ($keys === []) {
            return [];
        }
        $prefixed = array_map(fn ($k) => $this->map($k), $keys);
        $raw = apcu_fetch($prefixed);

        $items = [];
        foreach ($keys as $k) {
            $p = $this->map($k);
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
     * Retrieves a cache item from the cache.
     *
     * @param string $key The key of the item to retrieve.
     * @return ApcuCacheItem The retrieved cache item. If no item exists for the given key, an empty cache item is created.
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
        return new ApcuCacheItem($this, $key);
    }

    /**
     * Iterates over the values of the given keys from the cache.
     *
     * @param array<string> $keys The keys of the items to retrieve.
     * @return iterable<string, mixed> An iterable of key => value pairs, where the value is the cached value for the given key, or the default value if it doesn't exist in the cache.
     */
    public function getItems(array $keys = []): iterable
    {
        foreach ($keys as $k) {
            yield $k => $this->getItem($k);
        }
    }

    /**
     * Checks if an item exists in the cache.
     *
     * @param string $key The key of the item to check.
     * @return bool TRUE if the item exists, FALSE otherwise.
     */
    public function hasItem(string $key): bool
    {
        return $this->getItem($key)->isHit();
    }

    /**
     * Deletes an item from the cache.
     *
     * @param string $key The identifier of the item to delete.
     * @return bool TRUE if the item was successfully deleted, FALSE otherwise.
     */
    public function deleteItem(string $key): bool
    {
        return apcu_delete($this->map($key));
    }

    /**
     * Removes multiple items from the cache.
     *
     * @param string[] $keys The identifiers of the items to remove.
     * @return bool TRUE if all items were successfully removed, FALSE otherwise.
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
     * Removes all items from the cache.
     *
     * @return bool TRUE if all items were successfully removed, FALSE otherwise.
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
     * Saves the given cache item.
     *
     * This method is required by \Psr\Cache\CacheItemPoolInterface.
     *
     * @param CacheItemInterface $item The cache item to save.
     * @return bool TRUE if the item was successfully saved, FALSE otherwise.
     *
     * @throws CacheInvalidArgumentException if $item is not an instance of ApcuCacheItem.
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
     * Adds a cache item to the deferred queue for later persistence.
     *
     * This method queues the given cache item, to be saved when the
     * `commit()` method is invoked. It does not persist the item immediately.
     *
     * @param CacheItemInterface $item The cache item to defer.
     * @return bool True if the item was successfully deferred, false if the item type is invalid.
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
     * Persists any deferred cache items.
     *
     * @return bool TRUE if all deferred items were successfully persisted, FALSE otherwise.
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
     * Lists all keys in the cache.
     *
     * This method retrieves an array of all cache keys in the APCu cache.
     * The keys are prefixed with the namespace prefix.
     *
     * @return string[] An array of cache keys
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
     * Counts the number of cache items stored in APCu.
     *
     * @return int The total number of cache items currently stored.
     */
    public function count(): int
    {
        return count($this->listKeys());
    }


    /**
     * Retrieves a value from the cache for the given key.
     *
     * If the item is found and is a cache hit, its value is returned.
     * Otherwise, null is returned.
     *
     * @param string $key The key of the item to retrieve.
     * @return mixed The cached value or null if the item is not found.
     */
    public function get(string $key): mixed
    {
        $item = $this->getItem($key);
        return $item->isHit() ? $item->get() : null;
    }


    /**
     * PSR-16 “set($key, $value, $ttl)”: set a value in the cache.
     *
     * @param string $key   The key of the item to store.
     * @param mixed  $value The value of the item to store.
     * @param int|null $ttl  Optional. The TTL value of this item. If no value is sent and
     *                       the driver supports TTL then the library may set a default value
     *                       for it or let the driver take care of that.
     *
     * @return bool TRUE if the value was successfully stored, FALSE otherwise.
     */
    public function set(string $key, mixed $value, ?int $ttl = null): bool
    {
        $item = $this->getItem($key);
        $item->set($value)->expiresAfter($ttl);
        return $this->save($item);
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
