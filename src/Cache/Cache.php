<?php

declare(strict_types=1);

namespace Infocyph\InterMix\Cache;

use ArrayAccess;
use BadMethodCallException;
use Countable;
use DateInterval;
use DateTime;
use Infocyph\InterMix\Exceptions\CacheInvalidArgumentException;
use Psr\Cache\CacheItemInterface;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Cache\InvalidArgumentException as Psr6InvalidArgumentException;
use Psr\SimpleCache\CacheInterface as SimpleCacheInterface;
use Psr\SimpleCache\InvalidArgumentException as SimpleCacheInvalidArgument;

/**
 * @implements CacheItemPoolInterface<string, mixed>
 * @implements SimpleCacheInterface<string, mixed>
 */
class Cache implements
    CacheItemPoolInterface,
    SimpleCacheInterface,
    ArrayAccess,
    Countable
{
    /**
     * Cache constructor.
     *
     * @param CacheItemPoolInterface $adapter Any PSR-6 cache pool.
     */
    public function __construct(private readonly CacheItemPoolInterface $adapter)
    {
    }

    /**
     * Static factory for file-based cache.
     *
     * @param string      $namespace Cache prefix. Will be suffixed to each key.
     * @param string|null $dir       Directory to store cache files (or null → sys temp dir).
     * @return static
     */
    public static function file(string $namespace = 'default', ?string $dir = null): self
    {
        return new self(new Adapter\FileCacheAdapter($namespace, $dir));
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
        ?\Memcached $client = null
    ): self {
        return new self(new Adapter\MemCacheAdapter($namespace, $servers, $client));
    }


    /**
     * Static factory for SQLite-based cache.
     *
     * @param string      $namespace Cache prefix. Will be suffixed to each key.
     * @param string|null $file       Path to SQLite file (or null → sys temp dir).
     * @return static
     */
    public static function sqlite(string $namespace = 'default', ?string $file = null): self
    {
        return new self(new Adapter\SqliteCacheAdapter($namespace, $file));
    }


    /**
     * Static factory for Redis cache.
     *
     * @param string      $namespace Cache prefix.
     * @param string      $dsn        DSN for Redis connection (e.g. 'redis://127.0.0.1:6379'),
     *                                or null to use the default ('redis://127.0.0.1:6379').
     * @param \Redis|null $client     Optional preconfigured Redis instance.
     * @return static
     */
    public static function redis(
        string $namespace = 'default',
        string $dsn = 'redis://127.0.0.1:6379',
        ?\Redis $client = null
    ): self {
        return new self(new Adapter\RedisCacheAdapter($namespace, $dsn, $client));
    }

    /**
     * Validates a cache key per PSR-16 rules (and reuses for PSR-6).
     *
     * @throws CacheInvalidArgumentException if the key is invalid.
     */
    private function validateKey(string $key): void
    {
        // PSR-16 stipulates that keys must be strings that do not contain
        // {}()/\: @ or control characters; we restrict further to A-Z, a-z, 0-9, _ . and -.
        if ($key === '' || !preg_match('/^[A-Za-z0-9_.\-]+$/', $key)) {
            throw new CacheInvalidArgumentException(
                'Invalid cache key; allowed characters: A-Z, a-z, 0-9, _, ., -'
            );
        }
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

        throw new CacheInvalidArgumentException(sprintf(
            'Invalid TTL type; expected null, int, or DateInterval, got %s',
            get_debug_type($ttl)
        ));
    }

    //
    // ────────────────────────── PSR-6 METHODS ──────────────────────────
    //

    public function getItem(string $key): CacheItemInterface
    {
        $this->validateKey($key);
        return $this->adapter->getItem($key);
    }

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

    /** Alias for iteration-based access */
    public function getItemsIterator(array $keys = []): iterable
    {
        return $this->getItems($keys);
    }

    public function hasItem(string $key): bool
    {
        $this->validateKey($key);
        return $this->adapter->hasItem($key);
    }

    public function clear(): bool
    {
        return $this->adapter->clear();
    }

    public function deleteItem(string $key): bool
    {
        $this->validateKey($key);
        return $this->adapter->deleteItem($key);
    }

    public function deleteItems(array $keys): bool
    {
        foreach ($keys as $k) {
            $this->validateKey((string)$k);
        }
        return $this->adapter->deleteItems($keys);
    }

    public function save(CacheItemInterface $item): bool
    {
        return $this->adapter->save($item);
    }

    public function saveDeferred(CacheItemInterface $item): bool
    {
        return $this->adapter->saveDeferred($item);
    }

    public function commit(): bool
    {
        return $this->adapter->commit();
    }

    //
    // ────────────────────────── PSR-16 METHODS ──────────────────────────
    //

    /**
     * Fetches a value from the cache. If the key does not exist, returns $default.
     *
     * @param string $key
     * @param mixed  $default
     * @return mixed
     * @throws SimpleCacheInvalidArgument if the key is invalid
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
     * Persists a value in the cache, optionally with a TTL.
     *
     * @param string                $key
     * @param mixed                 $value
     * @param int|DateInterval|null $ttl   Time-to-live in seconds or a DateInterval
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
     * Wipes out the entire cache.
     *
     * @return bool
     */
    public function clearCache(): bool
    {
        return $this->clear();
    }

    /**
     * Obtains multiple values by their keys.
     *
     * @param iterable<int|string, string> $keys
     * @param mixed                        $default
     * @return iterable<string, mixed>
     * @throws SimpleCacheInvalidArgument if any key is invalid
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
     * Persists multiple key ⇒ value pairs to the cache.
     *
     * @param iterable<int|string, mixed> $values  key ⇒ value mapping
     * @param int|DateInterval|null       $ttl     TTL for all items
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
            if (! $ok) {
                $allSucceeded = false;
            }
        }

        return $allSucceeded;
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
            if (! $this->delete($k)) {
                $allSucceeded = false;
            }
        }
        return $allSucceeded;
    }

    /**
     * Determines whether an item exists in the cache.
     *
     * @param string $key
     * @return bool
     * @throws SimpleCacheInvalidArgument if the key is invalid
     */
    public function has(string $key): bool
    {
        return $this->hasItem($key);
    }

    //
    // ────────────────────────── ArrayAccess / Magic ──────────────────────────
    //

    public function offsetExists(mixed $offset): bool
    {
        return $this->has((string)$offset);
    }

    public function offsetGet(mixed $offset): mixed
    {
        return $this->get((string)$offset);
    }

    public function offsetSet(mixed $offset, mixed $value): void
    {
        // Default TTL to null
        $this->set((string)$offset, $value, null);
    }

    public function offsetUnset(mixed $offset): void
    {
        $this->delete((string)$offset);
    }

    public function __get(string $name): mixed
    {
        return $this->get($name);
    }

    public function __set(string $name, mixed $value): void
    {
        $this->set($name, $value, null);
    }

    public function __isset(string $name): bool
    {
        return $this->has($name);
    }

    public function __unset(string $name): void
    {
        $this->delete($name);
    }

    public function count(): int
    {
        // Delegate to adapter if it implements Countable
        return $this->adapter instanceof Countable
            ? count($this->adapter)
            : iterator_count($this->adapter->getItems([]));
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
}
