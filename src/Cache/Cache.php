<?php

declare(strict_types=1);

namespace Infocyph\InterMix\Cache;

use ArrayAccess;
use BadMethodCallException;
use Countable;
use Infocyph\InterMix\Exceptions\CacheInvalidArgumentException;
use Psr\Cache\CacheItemInterface;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Cache\InvalidArgumentException;

readonly class Cache implements
    CacheItemPoolInterface,
    ArrayAccess,
    Countable
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
     * Static factory for file-based cache.
     *
     * @param string $namespace Cache prefix. Will be suffixed to each key.
     * @param ?string $dir Directory to store cache files. If null, uses the system temporary directory.
     *
     * @return static
     */
    public static function file(string $namespace = 'default', ?string $dir = null): self
    {
        return new self(new Adapter\FileCacheAdapter($namespace, $dir));
    }


    /**
     * Static factory for APCu cache.
     *
     * @param string $namespace Cache prefix. Will be suffixed to each key.
     *
     * @return static
     */
    public static function apcu(string $namespace = 'default'): self
    {
        return new self(new Adapter\ApcuCacheAdapter($namespace));
    }

    /**
     * Static factory for Memcached cache.
     *
     * @param string $namespace Cache prefix.
     * @param array $servers List of [host, port, weight] triples. Defaults to 127.0.0.1:11211.
     * @param ?\Memcached $client Optional pre-configured Memcached instance.
     *
     * @return self
     */
    public static function memcache(
        string $namespace = 'default',
        array $servers = [['127.0.0.1', 11211, 0]],
        ?\Memcached $client = null,
    ): self {
        return new self(new Adapter\MemCacheAdapter($namespace, $servers, $client));
    }

    /**
     * Static factory for SQLite cache.
     *
     * @param string $namespace The namespace prefix for cache keys.
     * @param string|null $file The file path for the SQLite database.
     *     If `null`, the system temporary directory will be used.
     * @return self An instance of Cache configured with a SQLite adapter.
     */
    public static function sqlite(
        string $namespace = 'default',
        ?string $file = null,
    ): self {
        return new self(new Adapter\SqliteCacheAdapter($namespace, $file));
    }

    /**
     * Static factory for Redis cache.
     *
     * @param string $namespace The namespace prefix for cache keys.
     * @param string $dsn The Data Source Name for the Redis server.
     * @param \Redis|null $client An optional pre-configured Redis client instance.
     * @return self An instance of Cache configured with a Redis adapter.
     */
    public static function redis(
        string $namespace = 'default',
        string $dsn = 'redis://127.0.0.1:6379',
        ?\Redis $client = null,
    ): self {
        return new self(new Adapter\RedisCacheAdapter($namespace, $dsn, $client));
    }

    /**
     * Validates the format of a cache key.
     *
     * Ensures the key is non-empty and consists only of
     * alphanumeric characters, underscores, periods, and hyphens.
     * Throws a CacheInvalidArgumentException if the key is invalid.
     *
     * @param string $key The cache key to validate.
     *
     * @throws CacheInvalidArgumentException If the key is invalid.
     */
    private function validateKey(string $key): void
    {
        if ($key === '' || !preg_match('/^[A-Za-z0-9_.\-]+$/', $key)) {
            throw new CacheInvalidArgumentException(
                'Invalid cache key; allowed characters: A-Z, a-z, 0-9, _, ., -',
            );
        }
    }

    /**
     * Retrieves a cache item by its unique key.
     *
     * This method validates the provided key and delegates the retrieval
     * of the cache item to the underlying adapter. If the key is invalid,
     * a CacheInvalidArgumentException is thrown.
     *
     * @param string $key The unique key of the cache item to retrieve.
     * @return CacheItemInterface The cache item associated with the specified key.
     * @throws CacheInvalidArgumentException|InvalidArgumentException If the key is invalid.
     */
    public function getItem(string $key): CacheItemInterface
    {
        $this->validateKey($key);
        return $this->adapter->getItem($key);
    }

    /**
     * Retrieves multiple cache items as an iterator.
     *
     * This method fetches cache items corresponding to the provided keys
     * and returns them as an iterable collection. If the keys array is empty,
     * an empty iterable is returned.
     *
     * @param array $keys The keys of the cache items to retrieve.
     * @return iterable An iterable collection of CacheItemInterface objects.
     * @throws InvalidArgumentException If any of the keys are invalid.
     */
    public function getItemsIterator(array $keys = []): iterable
    {
        return $this->adapter->getItems($keys);
    }

    /**
     * PSR-6 method to retrieve multiple items from the cache.
     *
     * Implementations may choose to use an adapter-specific method (e.g.
     * `multiFetch`) if available, or fall back to calling `getItem` for each
     * key.
     *
     * If the input array is empty, an empty iterator is returned.
     *
     * @param array $keys cache keys
     * @return iterable an iterator over CacheItemInterface objects
     * @throws InvalidArgumentException
     */
    public function getItems(array $keys = []): iterable
    {
        if ($keys === []) {
            return new \EmptyIterator();
        }

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
     * Checks if a cache item exists for the given key.
     *
     * This method validates the key format and delegates the existence check
     * to the underlying adapter.
     *
     * @param string $key The key of the cache item to check.
     * @return bool Returns true if the cache item exists, false otherwise.
     * @throws CacheInvalidArgumentException|InvalidArgumentException if the key is invalid.
     */
    public function hasItem(string $key): bool
    {
        $this->validateKey($key);
        return $this->adapter->hasItem($key);
    }

    /**
     * Clears all cache items.
     *
     * This method delegates the clearing operation to the underlying adapter,
     * which removes all items from the cache.
     *
     * @return bool Returns true if the cache was successfully cleared, false otherwise.
     */
    public function clear(): bool
    {
        return $this->adapter->clear();
    }

    /**
     * Deletes a cache item by its key.
     *
     * Validates the key format and delegates the deletion to the underlying adapter.
     *
     * @param string $key The key of the cache item to delete.
     *
     * @return bool Returns true if the item was successfully deleted, false otherwise.
     * @throws CacheInvalidArgumentException|InvalidArgumentException if the key is invalid.
     */
    public function deleteItem(string $key): bool
    {
        $this->validateKey($key);
        return $this->adapter->deleteItem($key);
    }

    /**
     * Removes multiple cache items in a single operation.
     *
     * @param array $keys identifiers of the cache items to delete
     *
     * @return bool true on success; false if any of the items could not be deleted
     * @throws InvalidArgumentException
     */
    public function deleteItems(array $keys): bool
    {
        return $this->adapter->deleteItems($keys);
    }

    /**
     * Saves a cache item.
     *
     * @param CacheItemInterface $item cache item to save
     *
     * @return bool true on success; false if the item could not be saved
     */
    public function save(CacheItemInterface $item): bool
    {
        return $this->adapter->save($item);
    }

    /**
     * Defer saving of a cache item until commit() is explicitly called.
     *
     * @see commit()
     *
     * @param CacheItemInterface $item
     *
     * @return bool true on success; false if the item could not be deferred
     */
    public function saveDeferred(CacheItemInterface $item): bool
    {
        return $this->adapter->saveDeferred($item);
    }

    /**
     * Commit any deferred cache items.
     *
     * @return bool true on success; false if any deferred item failed to commit
     */
    public function commit(): bool
    {
        return $this->adapter->commit();
    }


    /**
     * Retrieve a cache item by key.
     *
     * If the underlying adapter has a dedicated get() method, it will be used.
     * Otherwise, the getItem() method will be used to fetch the item, and its
     * get() method will return the cached value.
     *
     * @param string $key cache key
     *
     * @return mixed the cached value, or null if not found
     * @throws InvalidArgumentException
     */
    public function get(string $key): mixed
    {
        return method_exists($this->adapter, 'get')
            ? $this->adapter->get($key)
            : $this->getItem($key)->get();
    }

    /**
     * Sets a value in the cache with an optional time-to-live (TTL).
     *
     * If the underlying adapter supports a direct 'set' method, it will be used.
     * Otherwise, the key-value pair is stored using a cache item, with the TTL
     * specified if provided.
     *
     * @param string $key The key under which to store the value.
     * @param mixed $value The value to be stored.
     * @param int|null $ttl Optional. The time-to-live in seconds. If null, the item
     *                      may persist indefinitely based on the adapter's behavior.
     *
     * @return bool Returns true if the operation was successful, false otherwise.
     * @throws InvalidArgumentException
     */
    public function set(string $key, mixed $value, ?int $ttl = null): bool
    {
        if (method_exists($this->adapter, 'set')) {
            return $this->adapter->set($key, $value, $ttl);
        }
        $item = $this->getItem($key)->set($value)->expiresAfter($ttl);
        return $this->save($item);
    }


    /**
     * Changes the namespace and directory for the pool.
     *
     * If the adapter implements {@see CacheItemPoolInterface::setNamespaceAndDirectory},
     * this call is forwarded to the adapter. Otherwise, a {@see \BadMethodCallException} is thrown.
     *
     * @param string      $namespace The new namespace.
     * @param string|null $dir        The new directory, or null to use the default.
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
     * Determine if a cache key exists.
     *
     * This method implements the ArrayAccess interface, allowing the use of
     * the `isset()` language construct to determine if a cache key is set,
     * e.g. `isset($cache['key'])`.
     *
     * @param mixed $offset The key of the cache item to check.
     * @return bool True if the cache item exists, false otherwise.
     * @throws InvalidArgumentException
     */
    public function offsetExists(mixed $offset): bool
    {
        return $this->hasItem((string)$offset);
    }

    /**
     * Retrieve a cache item.
     *
     * This method implements the ArrayAccess interface, allowing access
     * to cache items using the square bracket syntax, e.g. `$value = $cache['key'];`.
     *
     * @param mixed $offset The key of the cache item to retrieve.
     * @return mixed The value of the cache item, or null if not found.
     * @throws InvalidArgumentException
     */
    public function offsetGet(mixed $offset): mixed
    {
        return $this->get((string)$offset);
    }

    /**
     * Set a cache item.
     *
     * Implements the ArrayAccess interface, for use with the square bracket
     * syntax, e.g. `$cache['key'] = $value;`.
     *
     * @param mixed $offset The key of the cache item to set.
     * @param mixed $value The value to set the cache item to.
     * @throws InvalidArgumentException
     */
    public function offsetSet(mixed $offset, mixed $value): void
    {
        $this->set((string)$offset, $value);
    }

    /**
     * Unset a cache item.
     *
     * Implements the ArrayAccess interface, for use with the `unset()` language construct.
     *
     * @param mixed $offset The key of the cache item to remove.
     * @return void
     * @throws InvalidArgumentException
     */
    public function offsetUnset(mixed $offset): void
    {
        $this->deleteItem((string)$offset);
    }


    /**
     * Magic method to get a property from the cache.
     *
     * This method allows for the use of property access syntax on the cache,
     * which internally calls get() to retrieve the specified item from the
     * cache.
     *
     * @param string $name The name of the property to retrieve.
     * @return mixed The stored value, or null if it does not exist.
     * @throws InvalidArgumentException
     */
    public function __get(string $name): mixed
    {
        return $this->get($name);
    }

    /**
     * Magic method to set a property in the cache.
     *
     * This method allows for the use of the assignment operator on cache items,
     * which internally calls set() to store the specified item in the cache.
     *
     * @param string $name The name of the property to set.
     * @param mixed $value The value to store in the cache.
     * @return void
     * @throws InvalidArgumentException
     */
    public function __set(string $name, mixed $value): void
    {
        $this->set($name, $value);
    }

    /**
     * Magic method to check if a property is set in the cache.
     *
     * This method allows for the use of the isset() function on cache items,
     * which internally calls hasItem() to check if the specified item exists in the cache.
     *
     * @param string $name The name of the property to check.
     * @return bool True if the property exists in the cache, false otherwise.
     * @throws InvalidArgumentException
     */
    public function __isset(string $name): bool
    {
        return $this->hasItem($name);
    }

    /**
     * Magic method to unset a property from the cache.
     *
     * This method allows for the use of the unset() function on cache items,
     * which internally calls deleteItem() to remove the specified item from the cache.
     *
     * @param string $name The name of the cache item to unset.
     * @throws InvalidArgumentException
     */
    public function __unset(string $name): void
    {
        $this->deleteItem($name);
    }

    /**
     * Get the number of items in the cache.
     *
     * If the adapter is a Countable, we delegate to it. Otherwise, we
     * manually count the number of items returned by getItems().
     * @throws InvalidArgumentException
     */
    public function count(): int
    {
        return $this->adapter instanceof Countable
            ? count($this->adapter)
            : iterator_count($this->adapter->getItems());
    }
}
