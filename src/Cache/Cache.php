<?php

declare(strict_types=1);

namespace Infocyph\InterMix\Cache;

use BadMethodCallException;
use Countable;
use DateInterval;
use DateTime;
use Infocyph\InterMix\Exceptions\CacheInvalidArgumentException;
use Psr\Cache\CacheItemInterface;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Cache\InvalidArgumentException as Psr6InvalidArgumentException;
use Psr\SimpleCache\InvalidArgumentException as SimpleCacheInvalidArgument;

readonly class Cache implements CacheInterface
{
    /**
     * Cache constructor.
     *
     * @param CacheItemPoolInterface $adapter Any PSR-6 cache pool.
     */
    public function __construct(private CacheItemPoolInterface $adapter)
    {
    }

    /**
     * Retrieves a value from the cache using magic property access.
     *
     * This method allows accessing cached values using property syntax.
     * It is equivalent to calling the `get()` method with the property name.
     *
     * @param string $name The key for which to retrieve the value.
     * @return mixed The value associated with the given key.
     * @throws SimpleCacheInvalidArgument|Psr6InvalidArgumentException if the key is invalid.
     */
    public function __get(string $name): mixed
    {
        return $this->get($name);
    }

    /**
     * Whether the given key is set in the cache.
     *
     * @param string $name
     * @return bool
     * @throws Psr6InvalidArgumentException
     */
    public function __isset(string $name): bool
    {
        return $this->has($name);
    }

    /**
     * Sets a value in the cache.
     *
     * Magic property setter, equivalent to calling `set($name, $value, null)`.
     *
     * @param string $name
     * @param mixed $value
     * @return void
     *
     * @throws SimpleCacheInvalidArgument if the key is invalid
     */
    public function __set(string $name, mixed $value): void
    {
        $this->set($name, $value, null);
    }

    /**
     * Magic method to unset an item in the cache.
     *
     * This method deletes the cache entry associated with the given name.
     *
     * @param string $name The name of the cache item to unset.
     *
     * @return void
     * @throws SimpleCacheInvalidArgument
     */
    public function __unset(string $name): void
    {
        $this->delete($name);
    }

    /**
     * Static factory for APCu-based cache.
     *
     * @param string $namespace Cache prefix. Will be suffixed to each key.
     * @return static
     */
    public static function apcu(string $namespace = 'default'): self
    {
        return new self(new Adapter\ApcuCacheAdapter($namespace));
    }

    /**
     * Static factory for file-based cache.
     *
     * @param string $namespace Cache prefix. Will be suffixed to each key.
     * @param string|null $dir Directory to store cache files (or null → sys temp dir).
     * @return static
     */
    public static function file(string $namespace = 'default', ?string $dir = null): self
    {
        return new self(new Adapter\FileCacheAdapter($namespace, $dir));
    }


    /**
     * Static factory for local cache selection.
     *
     * Determines the appropriate caching mechanism based on the availability of the APCu extension.
     * If APCu is enabled, it returns an APCu-based cache; otherwise, it defaults to a file-based cache.
     *
     * @param string $namespace Cache prefix. Will be suffixed to each key.
     * @param string|null $dir Directory to store cache files (or null → sys temp dir), used if APCu is not enabled.
     * @return static An instance of the cache using the selected adapter.
     */
    public static function local(
        string $namespace = 'default',
        ?string $dir = null,
    ): self {
        if (extension_loaded('apcu') && apcu_enabled()) {
            return self::apcu($namespace);
        }

        return self::file($namespace, $dir);
    }

    /**
     * Static factory for Memcached-based cache.
     *
     * @param string $namespace Cache prefix. Will be suffixed to each key.
     * @param array $servers Memcached servers as an array of `[host, port, weight]`.
     *                       The `weight` is a float between 0 and 1, and defaults to 0.
     * @param \Memcached|null $client Optional preconfigured Memcached instance.
     * @return static
     */
    public static function memcache(
        string $namespace = 'default',
        array $servers = [['127.0.0.1', 11211, 0]],
        ?\Memcached $client = null,
    ): self {
        return new self(new Adapter\MemCacheAdapter($namespace, $servers, $client));
    }

    /**
     * Static factory for Redis cache.
     *
     * @param string $namespace Cache prefix.
     * @param string $dsn DSN for Redis connection (e.g. 'redis://127.0.0.1:6379'),
     *                                or null to use the default ('redis://127.0.0.1:6379').
     * @param \Redis|null $client Optional preconfigured Redis instance.
     * @return static
     */
    public static function redis(
        string $namespace = 'default',
        string $dsn = 'redis://127.0.0.1:6379',
        ?\Redis $client = null,
    ): self {
        return new self(new Adapter\RedisCacheAdapter($namespace, $dsn, $client));
    }

    /**
     * Static factory for SQLite-based cache.
     *
     * @param string $namespace Cache prefix. Will be suffixed to each key.
     * @param string|null $file Path to SQLite file (or null → sys temp dir).
     * @return static
     */
    public static function sqlite(string $namespace = 'default', ?string $file = null): self
    {
        return new self(new Adapter\SqliteCacheAdapter($namespace, $file));
    }

    /**
     * Removes all items from the cache.
     *
     * @return bool
     *     True if the operation was successful, false otherwise.
     */
    public function clear(): bool
    {
        return $this->adapter->clear();
    }

    /**
     * Wipes out the entire cache.
     *
     * @return bool
     */
    public function clearCache(): bool
    {
        return $this->clear();
    }

    /**
     * Commits any deferred cache items.
     *
     * If the underlying adapter supports deferred cache items, this
     * method will persist all items that have been added to the deferred
     * queue. If the adapter does not support deferred cache items, this
     * method is a no-op.
     *
     * @return bool True if all deferred items were successfully saved, false otherwise.
     */
    public function commit(): bool
    {
        return $this->adapter->commit();
    }

    /**
     * Returns the number of items in the cache.
     *
     * If the adapter implements the {@see Countable} interface, it will be
     * used to retrieve the count. Otherwise, this method will use the
     * {@see iterable} interface to count the items.
     *
     * @return int
     * @throws Psr6InvalidArgumentException
     */
    public function count(): int
    {
        return $this->adapter instanceof Countable
            ? count($this->adapter)
            : iterator_count($this->adapter->getItems([]));
    }

    /**
     * Delete an item from the cache.
     *
     * @param string $key
     * @return bool
     * @throws SimpleCacheInvalidArgument if the key is invalid
     */
    public function delete(string $key): bool
    {
        $this->validateKey($key);

        if (method_exists($this->adapter, 'delete')) {
            try {
                return $this->adapter->delete($key);
            } catch (\Psr\SimpleCache\InvalidArgumentException $e) {
                throw $e;
            }
        }

        // Fall back to PSR-6 deleteItem()
        try {
            return $this->deleteItem($key);
        } catch (Psr6InvalidArgumentException $e) {
            throw new CacheInvalidArgumentException($e->getMessage(), 0, $e);
        }
    }

    /**
     * Deletes a single item from the cache.
     *
     * This method deletes the item from the cache if it exists. If the item does
     * not exist, it is silently ignored.
     *
     * @param string $key
     *     The key of the item to delete.
     *
     * @return bool
     *     True if the item was successfully deleted, false otherwise.
     * @throws Psr6InvalidArgumentException
     */
    public function deleteItem(string $key): bool
    {
        $this->validateKey($key);
        return $this->adapter->deleteItem($key);
    }

    /**
     * Deletes multiple items from the cache.
     *
     * @param string[] $keys The array of keys to delete.
     *
     * @return bool True if all items were successfully deleted, false otherwise.
     * @throws Psr6InvalidArgumentException
     */
    public function deleteItems(array $keys): bool
    {
        foreach ($keys as $k) {
            $this->validateKey((string)$k);
        }
        return $this->adapter->deleteItems($keys);
    }

    /**
     * Deletes multiple keys from the cache.
     *
     * @param iterable<int|string, string> $keys
     * @return bool
     * @throws SimpleCacheInvalidArgument if any key is invalid
     */
    public function deleteMultiple(iterable $keys): bool
    {
        $allSucceeded = true;
        foreach ($keys as $k) {
            /** @var string $k */
            $this->validateKey($k);
            if (!$this->delete($k)) {
                $allSucceeded = false;
            }
        }
        return $allSucceeded;
    }

    /**
     * Fetches a value from the cache. If the key does not exist, returns $default.
     *
     * @param string $key
     * @param mixed $default
     * @return mixed
     * @throws SimpleCacheInvalidArgument|Psr6InvalidArgumentException if the key is invalid
     */
    public function get(string $key, mixed $default = null): mixed
    {
        $this->validateKey($key);

        // If $default is a callable, do a PSR-6 “compute & save” on cache miss.
        if (is_callable($default)) {
            try {
                // Fetch the PSR-6 item (may be a miss or a hit)
                $item = $this->getItem($key);
            } catch (Psr6InvalidArgumentException $e) {
                throw new CacheInvalidArgumentException($e->getMessage(), 0, $e);
            }

            // If we already have it, just return
            if ($item->isHit()) {
                return $item->get();
            }

            // Otherwise, call the user’s callback, passing in the (empty) CacheItem
            // They can set expiresAfter(...) inside the callback if desired.
            /** @var CacheItemInterface $item */
            $computed = $default($item);

            // Store the returned value in the cache item, then persist it
            $item->set($computed);
            $this->save($item);

            return $computed;
        }

        // If $default is not callable, proceed as before…

        // 1) If the adapter itself exposes a direct get($key), use it:
        if (method_exists($this->adapter, 'get')) {
            try {
                $value = $this->adapter->get($key);
            } catch (Psr6InvalidArgumentException $e) {
                throw new CacheInvalidArgumentException($e->getMessage(), 0, $e);
            }
            return $value ?? $default;
        }

        // 2) Otherwise, fall back to PSR-6 getItem():
        try {
            $item = $this->getItem($key);
        } catch (Psr6InvalidArgumentException $e) {
            throw new CacheInvalidArgumentException($e->getMessage(), 0, $e);
        }

        return $item->isHit() ? $item->get() : $default;
    }

    /**
     * Retrieves a Cache Item representing the specified key.
     *
     * This method returns a CacheItemInterface object containing the cached value.
     *
     * @param string $key
     *     The key of the item to retrieve.
     *
     * @return CacheItemInterface
     *     The retrieved Cache Item.
     * @throws CacheInvalidArgumentException
     *     If the $key is invalid or if a CacheLoader is not available when
     *     the value is not found.
     *
     */
    public function getItem(string $key): CacheItemInterface
    {
        $this->validateKey($key);
        return $this->adapter->getItem($key);
    }

    /**
     * Returns an iterable of {@see CacheItemInterface} objects for the given
     * keys.
     *
     * If no keys are provided, an empty iterator is returned.
     *
     * If the adapter supports it, the method will use the adapter's
     * `multiFetch` method. Otherwise, it iterates over the keys and calls
     * `getItem` on each key.
     *
     * @param string[] $keys
     *     An array of keys to fetch from the cache.
     *
     * @return iterable<CacheItemInterface>
     *     An iterable of CacheItemInterface objects.
     */
    public function getItems(array $keys = []): iterable
    {
        // If empty, return empty iterator
        if ($keys === []) {
            return new \EmptyIterator();
        }

        // Attempt adapter-specific multiFetch
        if (method_exists($this->adapter, 'multiFetch')) {
            return $this->adapter->multiFetch($keys);
        }

        $out = [];
        foreach ($keys as $k) {
            $out[$k] = $this->getItem($k);
        }
        return $out;
    }


    /**
     * Returns an iterable of {@see CacheItemInterface} objects for the given
     * keys.
     *
     * If no keys are provided, an empty iterator is returned.
     *
     * This method is a wrapper for `getItems()`, and is intended for use with
     * iterators.
     *
     * @param string[] $keys
     *     An array of keys to fetch from the cache.
     *
     * @return iterable<CacheItemInterface>
     *     An iterable of CacheItemInterface objects.
     */
    public function getItemsIterator(array $keys = []): iterable
    {
        return $this->getItems($keys);
    }

    /**
     * Obtains multiple values by their keys.
     *
     * @param iterable<int|string, string> $keys
     * @param mixed $default
     * @return iterable<string, mixed>
     * @throws SimpleCacheInvalidArgument|Psr6InvalidArgumentException if any key is invalid
     */
    public function getMultiple(iterable $keys, mixed $default = null): iterable
    {
        $result = [];
        foreach ($keys as $k) {
            /** @var string $k */
            $this->validateKey($k);
            $result[$k] = $this->get($k, $default);
        }
        return $result;
    }

    /**
     * Determines whether an item exists in the cache.
     *
     * @param string $key
     * @return bool
     * @throws Psr6InvalidArgumentException if the key is invalid
     */
    public function has(string $key): bool
    {
        return $this->hasItem($key);
    }

    /**
     * Checks if an item is present in the cache.
     *
     * @param string $key
     *     The key to check.
     *
     * @return bool
     *     True if the item exists in the cache, false otherwise.
     * @throws Psr6InvalidArgumentException
     */
    public function hasItem(string $key): bool
    {
        $this->validateKey($key);
        return $this->adapter->hasItem($key);
    }

    /**
     * Required by interface ArrayAccess.
     *
     * {@inheritdoc}
     *
     * @throws Psr6InvalidArgumentException
     * @see has()
     */
    public function offsetExists(mixed $offset): bool
    {
        return $this->has((string)$offset);
    }

    /**
     * Retrieves the value for the specified offset from the cache.
     *
     * This method allows the use of array-like syntax to retrieve a value
     * from the cache. The offset is converted to a string before retrieval.
     *
     * @param mixed $offset The key at which to retrieve the value.
     *
     * @return mixed The value at the specified offset.
     * @throws SimpleCacheInvalidArgument|Psr6InvalidArgumentException if the key is invalid
     */
    public function offsetGet(mixed $offset): mixed
    {
        return $this->get((string)$offset);
    }

    /**
     * Sets a value in the cache at the specified offset.
     *
     * This method allows the use of array-like syntax to store a value
     * in the cache. The offset is converted to a string before storing.
     * The time-to-live (TTL) for the cache entry is set to null by default.
     *
     * @param mixed $offset The key at which to set the value.
     * @param mixed $value The value to be stored at the specified offset.
     *
     * @return void
     * @throws SimpleCacheInvalidArgument if the key is invalid
     */
    public function offsetSet(mixed $offset, mixed $value): void
    {
        $this->set((string)$offset, $value, null);
    }

    /**
     * Unsets a key from the cache.
     *
     * @param string $offset
     * @return void
     * @throws Psr6InvalidArgumentException|SimpleCacheInvalidArgument if the key is invalid
     */
    public function offsetUnset(mixed $offset): void
    {
        $this->delete((string)$offset);
    }

    /**
     * Persists a cache item immediately.
     *
     * This method will throw a Psr6InvalidArgumentException if the item does not
     * implement CacheItemInterface.
     *
     * @param CacheItemInterface $item
     *     The cache item to persist.
     *
     * @return bool
     *     True if the cache item was successfully persisted, false otherwise.
     * @throws Psr6InvalidArgumentException
     *     If the item does not implement CacheItemInterface.
     */
    public function save(CacheItemInterface $item): bool
    {
        return $this->adapter->save($item);
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
        return $this->adapter->saveDeferred($item);
    }

    /**
     * Persists a value in the cache, optionally with a TTL.
     *
     * @param string $key
     * @param mixed $value
     * @param int|DateInterval|null $ttl Time-to-live in seconds or a DateInterval
     * @return bool
     * @throws SimpleCacheInvalidArgument if the key or TTL is invalid
     */
    public function set(string $key, mixed $value, mixed $ttl = null): bool
    {
        $this->validateKey($key);
        $ttlSeconds = $this->normalizeTtl($ttl);

        if (method_exists($this->adapter, 'set')) {
            try {
                return $this->adapter->set($key, $value, $ttlSeconds);
            } catch (Psr6InvalidArgumentException $e) {
                throw new CacheInvalidArgumentException($e->getMessage(), 0, $e);
            }
        }

        // Fall back to PSR-6 approach
        try {
            $item = $this->getItem($key)->set($value)->expiresAfter($ttlSeconds);
            return $this->save($item);
        } catch (Psr6InvalidArgumentException $e) {
            throw new CacheInvalidArgumentException($e->getMessage(), 0, $e);
        }
    }

    /**
     * Persists multiple key ⇒ value pairs to the cache.
     *
     * @param iterable<int|string, mixed> $values key ⇒ value mapping
     * @param int|DateInterval|null $ttl TTL for all items
     * @return bool
     * @throws SimpleCacheInvalidArgument if any key is invalid
     */
    public function setMultiple(iterable $values, mixed $ttl = null): bool
    {
        $ttlSeconds = $this->normalizeTtl($ttl);
        $allSucceeded = true;

        foreach ($values as $k => $v) {
            /** @var string $k */
            $this->validateKey($k);
            $ok = $this->set($k, $v, $ttlSeconds);
            if (!$ok) {
                $allSucceeded = false;
            }
        }

        return $allSucceeded;
    }

    /**
     * Changes the namespace and directory for the pool.
     *
     * If the adapter implements {@see CacheItemPoolInterface::setNamespaceAndDirectory},
     * this call is forwarded to the adapter. Otherwise, a {@see \BadMethodCallException} is thrown.
     *
     * @param string $namespace The new namespace.
     * @param string|null $dir The new directory, or null to use the default.
     *
     * @throws BadMethodCallException if the adapter does not support this method.
     */
    public function setNamespaceAndDirectory(string $namespace, ?string $dir = null): void
    {
        if (method_exists($this->adapter, 'setNamespaceAndDirectory')) {
            $this->adapter->setNamespaceAndDirectory($namespace, $dir);
            return;
        }
        throw new BadMethodCallException(
            sprintf('%s does not support setNamespaceAndDirectory()', $this->adapter::class),
        );
    }

    /**
     * Converts a PSR-16 TTL (int|DateInterval|null) into an integer number of seconds.
     */
    private function normalizeTtl(mixed $ttl): ?int
    {
        if ($ttl === null) {
            return null;
        }

        if (is_int($ttl)) {
            return $ttl >= 0 ? $ttl : throw new CacheInvalidArgumentException('Negative TTL not allowed');
        }

        if ($ttl instanceof DateInterval) {
            $now = new DateTime();
            return max(0, $now->add($ttl)->getTimestamp() - (new DateTime())->getTimestamp());
        }

        throw new CacheInvalidArgumentException(
            sprintf(
                'Invalid TTL type; expected null, int, or DateInterval, got %s',
                get_debug_type($ttl),
            ),
        );
    }

    /**
     * Validates a cache key per PSR-16 rules (and reuses for PSR-6).
     *
     * @throws CacheInvalidArgumentException if the key is invalid.
     */
    private function validateKey(string $key): void
    {
        if ($key === '' || !preg_match('/^[A-Za-z0-9_.\-]+$/', $key)) {
            throw new CacheInvalidArgumentException(
                'Invalid cache key; allowed characters: A-Z, a-z, 0-9, _, ., -',
            );
        }
    }
}
