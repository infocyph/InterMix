<?php

declare(strict_types=1);

namespace Infocyph\InterMix\Cache\Adapter;

use Countable;
use Psr\Cache\InvalidArgumentException;
use Redis;
use RuntimeException;
use Psr\Cache\CacheItemInterface;
use Psr\Cache\CacheItemPoolInterface;
use Infocyph\InterMix\Cache\Item\RedisCacheItem;
use Infocyph\InterMix\Serializer\ValueSerializer;
use Infocyph\InterMix\Exceptions\CacheInvalidArgumentException;

class RedisCacheAdapter implements CacheItemPoolInterface, Countable
{
    private readonly Redis $redis;
    private readonly string $ns;
    private array $deferred = [];

    /**
     * Constructs a RedisCacheAdapter.
     *
     * @param string $namespace The cache key prefix (namespace).
     * @param string $dsn The Data Source Name for the Redis server.
     * @param Redis|null $client An optional pre-configured Redis client instance.
     *
     * @throws RuntimeException If the phpredis extension is not enabled.
     */
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


    /**
     * Establish a connection to Redis using a DSN (Data Source Name).
     *
     * The DSN is a string in the format:
     *   `redis://[pass@]host[:port][/db]`
     *   • `pass` is the password for Redis.
     *   • `host` is the host name or IP address.
     *   • `port` is the port number (default: 6379).
     *   • `db` is the database number (default: 0).
     *
     * @param string $dsn The DSN string.
     * @return Redis The connected Redis instance.
     * @throws RuntimeException If the DSN is invalid.
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
     * Namespaced key constructor.
     *
     * @param string $key Key without namespace prefix.
     *
     * @return string "<ns>:<userKey>"
     */
    private function map(string $key): string
    {
        return $this->ns . ':' . $key;
    }


    /**
     * Retrieves multiple cache items by their unique keys.
     *
     * This method retrieves multiple cache items efficiently by leveraging
     * Redis's `MGET` command to fetch all items in a single call.
     * Each key is prefixed with the namespace to avoid collisions.
     *
     * @param array $keys List of keys identifying the cache items to retrieve.
     *
     * @return array An associative array of RedisCacheItem objects, keyed by the original cache key.
     */
    public function multiFetch(array $keys): array
    {
        $prefixed = array_map(fn ($k) => $this->map($k), $keys);
        $rawVals  = $this->redis->mget($prefixed);

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
     * {@inheritdoc}
     *
     * @param string $key The key of the item to retrieve.
     *
     * @return RedisCacheItem
     *      The retrieved cache item or a newly created
     *      RedisCacheItem if the key was not found.
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
     * Checks if a cache item exists for the given key.
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
        return $this->redis->exists($this->map($key)) === 1
            && $this->getItem($key)->isHit();
    }


    /**
     * Saves the cache item to the cache.
     *
     * @param CacheItemInterface $item The cache item to save.
     *
     * @return bool TRUE if the item was successfully saved, FALSE otherwise.
     *
     * @throws CacheInvalidArgumentException If the given item is not an instance of RedisCacheItem.
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
     * Removes a single item from the cache.
     *
     * @param string $key The key to remove from the cache
     * @return bool True if the item was successfully deleted, false otherwise
     */
    public function deleteItem(string $key): bool
    {
        return (bool)$this->redis->del($this->map($key));
    }

    /**
     * Removes multiple items from the cache.
     *
     * @param string[] $keys The identifiers to remove from the cache
     * @return bool True if all items were successfully deleted, false otherwise
     */
    public function deleteItems(array $keys): bool
    {
        $full = array_map(fn ($k) => $this->map($k), $keys);
        return $this->redis->del($full) === count($keys);
    }

    /**
     * Clears all cache items in the current namespace.
     *
     * This method uses the SCAN command to iterate over keys within the
     * current namespace and deletes them from the Redis store. It is
     * efficient for large datasets as it iterates in chunks, preventing
     * server overload. After clearing, the deferred queue is also emptied.
     *
     * @return bool Returns true upon successful completion.
     */
    public function clear(): bool
    {
        /* use SCAN to delete only this namespace */
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
     * Queues the given cache item for deferred saving in the Redis cache pool.
     *
     * If the provided item is not an instance of RedisCacheItem, the method
     * returns false. Otherwise, the item is added to the internal deferred
     * queue and true is returned. The item will be persisted in the cache
     * when the commit() method is called.
     *
     * @param CacheItemInterface $item The cache item to be deferred.
     * @return bool True if the item was successfully deferred, false otherwise.
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
     * @return int the number of cache items in the pool
     *
     * This implementation uses the SCAN iterator to count the number of keys
     * in the namespace, without loading the values.  The maximum number of
     * keys returned per iteration is 1000.
     *
     * CAUTION: This method is not atomic.  If items are added or removed while
     * this method is running, the count may not reflect the final state.
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
