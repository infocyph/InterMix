<?php

declare(strict_types=1);

namespace Infocyph\InterMix\Cache\Adapter;

use Countable;
use Infocyph\InterMix\Cache\Item\RedisCacheItem;
use Infocyph\InterMix\Exceptions\CacheInvalidArgumentException;
use Infocyph\InterMix\Serializer\ValueSerializer;
use Psr\Cache\CacheItemInterface;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Cache\InvalidArgumentException;
use Redis;
use RuntimeException;

class RedisCacheAdapter implements CacheItemPoolInterface, Countable
{
    private readonly string $ns;
    private readonly Redis $redis;
    private array $deferred = [];

    /**
     * Constructs a RedisCacheAdapter instance.
     *
     * Initializes the adapter with a namespace for cache key mapping,
     * a DSN for connecting to a Redis server, and an optional Redis client.
     * If the Redis client is not provided, a new connection will be established
     * using the given DSN.
     *
     * @param string $namespace The namespace to use for cache keys, defaults to 'default'.
     * @param string $dsn The DSN string for connecting to Redis, defaults to 'redis://127.0.0.1:6379'.
     * @param Redis|null $client An optional Redis client instance. If not provided, a new connection is made.
     *
     * @throws RuntimeException If the phpredis extension is not loaded.
     */
    public function __construct(
        string $namespace = 'default',
        string $dsn = 'redis://127.0.0.1:6379',
        ?Redis $client = null,
    ) {
        if (!class_exists(Redis::class)) {
            throw new RuntimeException('phpredis extension not loaded');
        }

        $this->ns = sanitize_cache_ns($namespace);
        $this->redis = $client ?? $this->connect($dsn);
    }

    /**
     * Clears the cache pool.
     *
     * This method will remove all items from the cache pool, including
     * deferred items. It is not intended to be called directly.
     *
     * @return bool TRUE if the cache pool was successfully cleared, FALSE otherwise.
     */
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

    /**
     * Persists any deferred cache items.
     *
     * This method is called by the CachePool implementation when it is
     * committed. It is not intended to be called directly.
     *
     * @return bool TRUE if all deferred items were successfully persisted,
     *              FALSE otherwise.
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
     * Counts the number of cache items.
     *
     * This method calculates the total number of items stored in the cache
     * by scanning through all keys with the current namespace prefix.
     *
     * @return int The total count of cache items.
     */
    public function count(): int
    {
        $iter = null;
        $count = 0;
        while ($keys = $this->redis->scan($iter, $this->ns . ':*', 1000)) {
            $count += count($keys);
        }
        return $count;
    }

    /**
     * Deletes a cache item.
     *
     * @param string $key The key to be deleted.
     *
     * @return bool True if the item was successfully deleted, false otherwise.
     */
    public function deleteItem(string $key): bool
    {
        return (bool) $this->redis->del($this->map($key));
    }

    /**
     * Deletes multiple cache items in a single operation.
     *
     * If all specified items are successfully deleted, true is returned.
     * If any of the items did not exist or could not be deleted, false is returned.
     *
     * @param string[] $keys An array of keys to delete.
     * @return bool TRUE if all items were successfully deleted, FALSE otherwise.
     */
    public function deleteItems(array $keys): bool
    {
        $full = array_map(fn ($k) => $this->map($k), $keys);
        return $this->redis->del($full) === count($keys);
    }


    /**
     * Retrieves the value of a cache item by its key.
     *
     * This method attempts to fetch the cache item associated with the given key.
     * If the item is found and is a cache hit, its value is returned. Otherwise,
     * null is returned.
     *
     * @param string $key The key for the cache item to retrieve.
     * @return mixed The value of the cache item if it exists and is a hit, or null otherwise.
     * @throws InvalidArgumentException
     */
    public function get(string $key): mixed
    {
        $item = $this->getItem($key);
        return $item->isHit() ? $item->get() : null;
    }

    /**
     * {@inheritdoc}
     *
     * If the item is found in redis, it will be unserialized and returned.
     * If the item is not found, a new RedisCacheItem will be created and returned.
     */
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

    /**
     * Returns an iterable of CacheItemInterface objects for the given keys.
     *
     * Returns an iterable of CacheItemInterface objects for the given keys. If no keys are provided,
     * an empty iterable will be returned.
     *
     * @param array $keys An array of keys to retrieve items for.
     *
     * @return iterable An iterable of CacheItemInterface objects.
     * @throws InvalidArgumentException
     */
    public function getItems(array $keys = []): iterable
    {
        foreach ($keys as $k) {
            yield $k => $this->getItem($k);
        }
    }

    /**
     * Confirms if the cache contains specified cache item.
     *
     * Note: This method MAY avoid loading the cache item data from Redis.
     * It does this by checking for the existence of the key in Redis directly.
     * If the key does not exist, or if the key has expired, or if the item is
     * considered "invalid" by the cache item implementation, then false is
     * returned. Otherwise, the method returns true.
     *
     * @param string $key The key of the cache item to check for.
     * @return bool True if the cache contains specified cache item, false otherwise.
     * @throws InvalidArgumentException
     */
    public function hasItem(string $key): bool
    {
        return $this->redis->exists($this->map($key)) === 1;
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

    /**
     * Returns an associative array of cache items for the given keys.
     *
     * Each cache item is an instance of RedisCacheItem, and is keyed by
     * the key originally passed to this method. If a key was not found in
     * the cache, the value will be an instance of RedisCacheItem with a
     * null value.
     *
     * @param string[] $keys An array of keys to retrieve items for.
     * @return RedisCacheItem[] An associative array of cache items.
     */
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

    /**
     * Saves a cache item into the Redis cache.
     *
     * This method serializes the given cache item and stores it in the Redis cache.
     * If the item has a time-to-live (TTL) value, it will be stored with that expiration time.
     *
     * @param CacheItemInterface $item The cache item to save.
     *
     * @throws CacheInvalidArgumentException If the item is not an instance of RedisCacheItem.
     *
     * @return bool True if the cache item was successfully saved, false otherwise.
     */
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

    /**
     * @internal
     * Adds the given cache item to the deferred queue.
     *
     * This method is called by the cache item when it is deferred
     * using the `saveDeferred()` method. It is not intended to be
     * called directly.
     *
     * @param RedisCacheItem $item The cache item to be deferred.
     *
     * @return bool TRUE if the item was successfully deferred, FALSE otherwise.
     */
    public function saveDeferred(CacheItemInterface $item): bool
    {
        if (!$item instanceof RedisCacheItem) {
            return false;
        }
        $this->deferred[$item->getKey()] = $item;
        return true;
    }


    /**
     * PSR-16: Sets a value in the cache.
     *
     * This method stores the given value in the cache under the specified key.
     * If a time-to-live (TTL) value is provided, the cache item will expire
     * after the specified number of seconds.
     *
     * @param string $key The key under which to store the value.
     * @param mixed $value The value to be stored in the cache.
     * @param int|null $ttl Optional. The time-to-live in seconds for the cache item.
     *                      If null, the item will not expire.
     * @return bool True if the value was successfully set in the cache, false otherwise.
     * @throws InvalidArgumentException
     */
    public function set(string $key, mixed $value, ?int $ttl = null): bool
    {
        $item = $this->getItem($key);
        $item->set($value)->expiresAfter($ttl);
        return $this->save($item);
    }

    /**
     * Establishes a connection to Redis.
     *
     * This method takes a DSN string in the format of:
     * `redis://[:password]@host[:port][/db]`
     *
     * If the password is not provided, no AUTH command will be sent.
     * If the port is not provided, the default port of 6379 will be used.
     * If the database is not provided, the default database of 0 will be used.
     *
     * @param string $dsn A DSN string to connect to Redis
     * @return Redis The connected Redis instance
     * @throws RuntimeException If the DSN string is invalid
     */
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

    /**
     * Maps a given key to a namespaced key.
     *
     * This method prefixes the provided key with the current
     * namespace to ensure uniqueness within the Redis cache.
     *
     * @param string $key The original key to be mapped.
     * @return string The namespaced key.
     */
    private function map(string $key): string
    {
        return $this->ns . ':' . $key;
    }
}
