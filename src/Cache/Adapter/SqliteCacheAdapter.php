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
use Psr\Cache\InvalidArgumentException;

class SqliteCacheAdapter implements CacheItemPoolInterface, Countable
{
    private readonly PDO $pdo;
    private readonly string $ns;
    private array $deferred = [];

    /**
     * @param string $namespace The namespace prefix for cache keys. The namespace is sanitized
     *     to allow only alphanumeric characters, underscores, and hyphens.
     * @param string|null $dbPath The file path for the SQLite database. If not specified, the
     *     system temporary directory will be used.
     */
    public function __construct(
        string $namespace = 'default',
        string $dbPath = null,
    ) {
        $this->ns = preg_replace('/[^A-Za-z0-9_\-]/', '_', $namespace);
        $file = $dbPath ?: sys_get_temp_dir() . "/cache_{$this->ns}.sqlite";

        $this->pdo = new PDO('sqlite:' . $file);
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        /* create schema once */
        $this->pdo->exec(
            'CREATE TABLE IF NOT EXISTS cache (
                key TEXT PRIMARY KEY,
                value BLOB NOT NULL,
                expires INTEGER
             )',
        );
        $this->pdo->exec('CREATE INDEX IF NOT EXISTS exp_idx ON cache(expires)');
    }


    /**
     * Validates the format of a cache key.
     *
     * This method ensures that the given key conforms to the allowed character set
     * and is not an empty string. The allowed characters are A-Z, a-z, 0-9, underscores (_),
     * periods (.), and hyphens (-). If the key is invalid, a CacheInvalidArgumentException
     * is thrown.
     *
     * @param string $key The cache key to validate.
     *
     * @throws CacheInvalidArgumentException if the key is empty or contains invalid characters.
     */
    private function validateKey(string $key): void
    {
        if ($key === '' || !preg_match('/^[A-Za-z0-9_.\-]+$/', $key)) {
            throw new CacheInvalidArgumentException(
                'Invalid SQLite cache key; allowed A-Z, a-z, 0-9, _, ., -',
            );
        }
    }

    /**
     * {@inheritDoc}
     *
     * This adapter uses a single SQL query with parameterized IN statement
     * to fetch multiple rows at once. If a key is expired, the row is deleted
     * on the fly and the item is returned as a cache miss.
     */
    public function multiFetch(array $keys): array
    {
        if ($keys === []) {
            return [];
        }

        // ---- build placeholder list (?, ?, ...) ---------------------------
        $marks = implode(',', array_fill(0, count($keys), '?'));

        // Each row = [key,value,expires]
        $stmt = $this->pdo->prepare(
            "SELECT key, value, expires
           FROM cache
          WHERE key IN ($marks)",
        );
        $stmt->execute($keys);

        /** @var array<string,array{value:string,expires:int|null}> $rows */
        $rows = [];
        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $r) {
            $rows[$r['key']] = ['value' => $r['value'], 'expires' => $r['expires']];
        }

        // ---- build CacheItemInterface array in original order -------------
        $items = [];
        $now = time();

        foreach ($keys as $k) {
            if (isset($rows[$k])) {
                $row = $rows[$k];

                // honour TTL
                if ($row['expires'] === null || $row['expires'] > $now) {
                    $val = ValueSerializer::unserialize($row['value']);
                    if ($val instanceof CacheItemInterface) {
                        $val = $val->get();
                    }
                    $items[$k] = new SqliteCacheItem($this, $k, $val, true);
                    continue;
                }

                // expired → fall through to miss (and optionally delete row)
                $this->pdo->prepare("DELETE FROM cache WHERE key = ?")->execute([$k]);
            }

            // cache miss
            $items[$k] = new SqliteCacheItem($this, $k);
        }

        return $items; // iterable keyed by requested keys
    }

    /**
     * {@inheritdoc}
     *
     * @param string $key The key of the cache item to retrieve.
     *
     * @return SqliteCacheItem
     *      The retrieved cache item or a newly created
     *      SqliteCacheItem if the key was not found.
     */
    public function getItem(string $key): SqliteCacheItem
    {
        $this->validateKey($key);

        $stmt = $this->pdo->prepare(
            'SELECT value, expires FROM cache WHERE key = :k LIMIT 1',
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

        /* cache miss or expired */
        $this->pdo->prepare('DELETE FROM cache WHERE key = :k')->execute([':k' => $key]);
        return new SqliteCacheItem($this, $key);
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
     * Checks if a cache item is a hit for the given key.
     *
     * This method retrieves the cache item associated with the specified key
     * and determines if it is a cache hit, indicating that the item exists
     * in the cache and has not expired.
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
     * Saves the cache item to the SQLite database.
     *
     * This method serializes the cache item and stores it in the SQLite
     * database with its key and expiration time. If the item already exists
     * in the database, it will be replaced. The expiration time is calculated
     * based on the item's TTL (time-to-live) if provided.
     *
     * @param CacheItemInterface $item The cache item to be saved.
     *
     * @return bool True if the operation was successful, false otherwise.
     *
     * @throws CacheInvalidArgumentException If the given item is not an
     * instance of SqliteCacheItem.
     */
    public function save(CacheItemInterface $item): bool
    {
        if (!$item instanceof SqliteCacheItem) {
            throw new CacheInvalidArgumentException('Wrong item class');
        }
        $blob = ValueSerializer::serialize($item);
        $exp = $item->ttlSeconds() ? time() + $item->ttlSeconds() : null;

        $stmt = $this->pdo->prepare(
            'REPLACE INTO cache(key,value,expires) VALUES(:k,:v,:e)',
        );
        return $stmt->execute([
            ':k' => $item->getKey(),
            ':v' => $blob,
            ':e' => $exp,
        ]);
    }

    /**
     * Deletes a cache item by its key.
     *
     * This method validates the provided key and attempts to remove the
     * corresponding entry from the SQLite cache table. It returns true
     * upon successful execution of the delete operation.
     *
     * @param string $key The key of the cache item to delete.
     * @return bool Returns true if the item was successfully deleted.
     * @throws CacheInvalidArgumentException|InvalidArgumentException if the key is invalid.
     */
    public function deleteItem(string $key): bool
    {
        $this->validateKey($key);
        $this->pdo->prepare('DELETE FROM cache WHERE key = :k')->execute([':k' => $key]);
        return true;
    }

    /**
     * Deletes multiple items from the cache.
     *
     * @param string[] $keys An array of keys to delete from the cache.
     *
     * @return bool True if all items were successfully deleted, false otherwise.
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
     * Deletes all cache entries in the pool.
     *
     * @return bool Always true, unless an error occurs.
     */
    public function clear(): bool
    {
        $this->pdo->exec('DELETE FROM cache');
        $this->deferred = [];
        return true;
    }


    /**
     * Adds a cache item to the deferred queue for saving.
     *
     * The given cache item is added to the internal deferred queue and
     * will be persisted in the SQLite cache pool when the commit() method
     * is called. If the item is not an instance of SqliteCacheItem, false
     * will be returned. Otherwise, true is returned.
     *
     * @param CacheItemInterface $item The cache item to be deferred.
     * @return bool True if the item was successfully deferred, false otherwise.
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
     * Saves any deferred cache items from the queue into the database.
     *
     * Iterates over the deferred queue and saves each item. If any of the
     * saves fail, the method will return false. Otherwise, it clears the
     * deferred queue and returns true.
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
     * Returns the number of cache items that are currently valid.
     *
     * This method queries the SQLite database to count all cache items
     * whose expiration time is either null or greater than the current
     * timestamp, indicating they are still valid.
     *
     * @return int The count of valid cache items.
     */
    public function count(): int
    {
        return (int)$this->pdo->query(
            'SELECT COUNT(*) FROM cache
             WHERE expires IS NULL OR expires > ' . time(),
        )->fetchColumn();
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
