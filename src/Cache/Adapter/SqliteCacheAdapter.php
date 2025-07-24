<?php

declare(strict_types=1);

namespace Infocyph\InterMix\Cache\Adapter;

use Countable;
use PDO;
use Psr\Cache\CacheItemInterface;
use Psr\Cache\CacheItemPoolInterface;
use Infocyph\InterMix\Cache\Item\SqliteCacheItem;
use Infocyph\InterMix\Serializer\ValueSerializer;
use Infocyph\InterMix\Exceptions\CacheInvalidArgumentException;

class SqliteCacheAdapter implements CacheItemPoolInterface, Countable
{
    private readonly PDO $pdo;
    private readonly string $ns;
    private array $deferred = [];

    /**
     * Constructs a new instance of the SQLite cache adapter.
     *
     * @param string $namespace The namespace to use for this cache pool.
     * @param string|null $dbPath The path to the SQLite database file. If null, the file will be created in the system's temp directory.
     */
    public function __construct(
        string $namespace = 'default',
        ?string $dbPath = null,
    ) {
        $this->ns = sanitize_cache_ns($namespace);
        $file = $dbPath ?: sys_get_temp_dir() . "/cache_{$this->ns}.sqlite";

        $this->pdo = new PDO('sqlite:' . $file);
        $this->pdo->exec('PRAGMA journal_mode=WAL; PRAGMA synchronous=NORMAL;');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        $this->pdo->exec(
            'CREATE TABLE IF NOT EXISTS cache (
                key TEXT PRIMARY KEY,
                value BLOB NOT NULL,
                expires INTEGER
             )'
        );
        $this->pdo->exec('CREATE INDEX IF NOT EXISTS exp_idx ON cache(expires)');
    }

    /**
     * Retrieves multiple cache items from the cache pool.
     *
     * This method fetches cache items corresponding to the provided
     * keys. If a cache item is found and is not expired, it returns
     * the cache item with its value. If a cache item is expired, it
     * deletes the cache entry and returns a cache item with a null
     * value. If a key does not exist in the cache, it also returns
     * a cache item with a null value.
     *
     * @param array $keys An array of cache keys to retrieve.
     * @return array An associative array of cache items, keyed by the
     *               original cache keys.
     */
    public function multiFetch(array $keys): array
    {
        if ($keys === []) {
            return [];
        }

        $marks = implode(',', array_fill(0, count($keys), '?'));
        $stmt = $this->pdo->prepare(
            "SELECT key, value, expires
             FROM cache
             WHERE key IN ($marks)"
        );
        $stmt->execute($keys);

        /** @var array<string,array{value:string,expires:int|null}> $rows */
        $rows = [];
        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $r) {
            $rows[$r['key']] = ['value' => $r['value'], 'expires' => $r['expires']];
        }

        $items = [];
        $now = time();

        foreach ($keys as $k) {
            if (isset($rows[$k])) {
                $row = $rows[$k];
                if ($row['expires'] === null || $row['expires'] > $now) {
                    $val = ValueSerializer::unserialize($row['value']);
                    if ($val instanceof CacheItemInterface) {
                        $val = $val->get();
                    }
                    $items[$k] = new SqliteCacheItem($this, $k, $val, true);
                    continue;
                }
                // expired → delete then miss
                $this->pdo->prepare("DELETE FROM cache WHERE key = ?")->execute([$k]);
            }
            $items[$k] = new SqliteCacheItem($this, $k);
        }

        return $items;
    }

    /**
     * Retrieves a cache item from the cache pool.
     *
     * This method retrieves a cache item from the cache pool by its
     * unique key. If the item does not exist or is expired, it will
     * return a CacheItemInterface object with a null value and a
     * CacheItemInterface::isHit() method that returns false.
     *
     * @param string $key The key of the cache item to retrieve.
     *
     * @return SqliteCacheItem The retrieved cache item or a null value if
     *         not found or expired.
     */
    public function getItem(string $key): SqliteCacheItem
    {
        $stmt = $this->pdo->prepare(
            'SELECT value, expires FROM cache WHERE key = :k LIMIT 1'
        );
        $stmt->execute([':k' => $key]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($row && (!$row['expires'] || $row['expires'] > time())) {
            /** @var SqliteCacheItem $item */
            $item = ValueSerializer::unserialize($row['value']);
            if ($item instanceof SqliteCacheItem && $item->isHit()) {
                return $item;
            }
        }

        // miss or expired
        $this->pdo->prepare('DELETE FROM cache WHERE key = :k')->execute([':k' => $key]);
        return new SqliteCacheItem($this, $key);
    }

    /**
     * Retrieves a collection of cache items from the cache pool.
     *
     * This method retrieves multiple cache items by their unique keys.
     * It is not guaranteed that all items will be retrieved; if an item
     * does not exist or is expired, it will not be returned.
     *
     * @param string[] $keys An array of keys to retrieve.
     *
     * @return iterable A traversable collection of cache items.
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
     * Note: This method MUST be used carefully to avoid a race
     * condition where the item is deleted between the call to
     * this method and the next method call to save the item.
     *
     * @param string $key The cache item key.
     *
     * @return bool TRUE if the item exists in the cache, FALSE otherwise.
     */
    public function hasItem(string $key): bool
    {
        return $this->getItem($key)->isHit();
    }

    /**
     * Saves a cache item to the database.
     *
     * This method is supposed to be used when the cache item needs
     * to be persisted in the cache pool. It is not intended to be
     * used very frequently.
     *
     * @param CacheItemInterface $item The cache item to save.
     *
     * @return bool TRUE if the item was successfully saved, FALSE otherwise.
     * @throws CacheInvalidArgumentException if the given item is not an instance of SqliteCacheItem.
     */
    public function save(CacheItemInterface $item): bool
    {
        if (!$item instanceof SqliteCacheItem) {
            throw new CacheInvalidArgumentException('Wrong item class');
        }
        $blob = ValueSerializer::serialize($item);
        $exp = $item->ttlSeconds() ? time() + $item->ttlSeconds() : null;

        $stmt = $this->pdo->prepare(
            'REPLACE INTO cache(key, value, expires) VALUES(:k, :v, :e)'
        );
        return $stmt->execute([
            ':k' => $item->getKey(),
            ':v' => $blob,
            ':e' => $exp,
        ]);
    }

    /**
     * Deletes a cache item.
     *
     * This method attempts to delete the cache item
     * associated with the specified key.
     *
     * @param string $key The cache key to delete.
     * @return bool True if the item was successfully deleted.
     */
    public function deleteItem(string $key): bool
    {
        $this->pdo->prepare('DELETE FROM cache WHERE key = :k')->execute([':k' => $key]);
        return true;
    }

    /**
     * Deletes multiple cache items by their keys.
     *
     * This method attempts to delete each cache item
     * specified in the array of keys. It iterates through
     * the keys and deletes the corresponding cache item.
     *
     * @param array $keys An array of cache keys to delete.
     * @return bool True if all items were successfully deleted.
     */
    public function deleteItems(array $keys): bool
    {
        foreach ($keys as $k) {
            $this->deleteItem($k);
        }
        return true;
    }

    /**
     * Clears the cache pool and the deferred queue.
     *
     * This method is supposed to be used when the entire cache pool
     * needs to be purged of all cache items. It is not intended to
     * be used very frequently.
     *
     * @return bool True if the cache was successfully cleared, false otherwise.
     */
    public function clear(): bool
    {
        $this->pdo->exec('DELETE FROM cache');
        $this->deferred = [];
        return true;
    }

    /**
     * Adds the given cache item to the internal deferred queue.
     *
     * This method enqueues the cache item for later persistence in
     * the cache pool. The item will not be saved immediately, but
     * will be stored when the commit() method is called.
     *
     * @param CacheItemInterface $item The cache item to be queued for deferred saving.
     * @return bool True if the item was successfully queued, false otherwise.
     * @internal
     */
    public function saveDeferred(CacheItemInterface $item): bool
    {
        if (!$item instanceof SqliteCacheItem) {
            return false;
        }
        $this->deferred[$item->getKey()] = $item;
        return true;
    }

    /**
     * Commits all deferred cache items to the database.
     *
     * This method attempts to save all items in the deferred queue
     * to the cache. Each item is processed and persisted. If all
     * items are successfully saved, the deferred queue is cleared.
     *
     * @return bool True if all deferred items were successfully saved, false otherwise.
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
     * Returns the number of cache items that are not expired.
     *
     * This method counts and returns the total number of items
     * in the cache that have no expiration or have an expiration
     * time in the future.
     *
     * @return int The number of valid cache items.
     */
    public function count(): int
    {
        return (int) $this->pdo->query(
            'SELECT COUNT(*) FROM cache WHERE expires IS NULL OR expires > ' . time()
        )->fetchColumn();
    }


    /**
     * Retrieves the value associated with the specified cache key.
     *
     * This method attempts to fetch the cache item for the given key
     * and returns its value if the item is a cache hit. If the item
     * does not exist or is expired, it returns null.
     *
     * @param string $key The cache key to retrieve.
     * @return mixed The cached value or null if not found or expired.
     */
    public function get(string $key): mixed
    {
        $item = $this->getItem($key);
        return $item->isHit() ? $item->get() : null;
    }


    /**
     * PSR-16: cache a raw value, with optional TTL.
     *
     * @param string $key
     * @param mixed $value
     * @param int|null $ttl
     * @return bool
     */
    public function set(string $key, mixed $value, ?int $ttl = null): bool
    {
        $item = $this->getItem($key);
        $item->set($value)->expiresAfter($ttl);
        return $this->save($item);
    }

    /**
     * Persists a cache item in the cache pool.
     *
     * This method is called by the cache item when it is persisted
     * using the `save()` method. It is not intended to be called
     * directly.
     *
     * @param SqliteCacheItem $i The cache item to persist.
     *
     * @return bool TRUE if the item was successfully persisted, FALSE otherwise.
     * @internal
     */
    public function internalPersist(SqliteCacheItem $i): bool
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
     * @param SqliteCacheItem $i The cache item to be queued for deferred saving.
     * @return bool True if the item was successfully queued, false otherwise.
     * @internal
     */
    public function internalQueue(SqliteCacheItem $i): bool
    {
        return $this->saveDeferred($i);
    }
}
