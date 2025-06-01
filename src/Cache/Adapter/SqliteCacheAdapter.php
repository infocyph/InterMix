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

    public function __construct(
        string $namespace = 'default',
        string $dbPath = null,
    ) {
        $this->ns = preg_replace('/[^A-Za-z0-9_\-]/', '_', $namespace);
        $file = $dbPath ?: sys_get_temp_dir() . "/cache_{$this->ns}.sqlite";

        $this->pdo = new PDO('sqlite:' . $file);
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

    public function getItems(array $keys = []): iterable
    {
        foreach ($keys as $k) {
            yield $k => $this->getItem($k);
        }
    }

    public function hasItem(string $key): bool
    {
        return $this->getItem($key)->isHit();
    }

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

    public function deleteItem(string $key): bool
    {
        $this->pdo->prepare('DELETE FROM cache WHERE key = :k')->execute([':k' => $key]);
        return true;
    }

    public function deleteItems(array $keys): bool
    {
        foreach ($keys as $k) {
            $this->deleteItem($k);
        }
        return true;
    }

    public function clear(): bool
    {
        $this->pdo->exec('DELETE FROM cache');
        $this->deferred = [];
        return true;
    }

    public function saveDeferred(CacheItemInterface $item): bool
    {
        if (!$item instanceof SqliteCacheItem) {
            return false;
        }
        $this->deferred[$item->getKey()] = $item;
        return true;
    }

    public function commit(): bool
    {
        $ok = true;
        foreach ($this->deferred as $k => $it) {
            $ok = $ok && $this->save($it);
            unset($this->deferred[$k]);
        }
        return $ok;
    }

    public function count(): int
    {
        return (int) $this->pdo->query(
            'SELECT COUNT(*) FROM cache WHERE expires IS NULL OR expires > ' . time()
        )->fetchColumn();
    }

    // ───────────────────────────────────────────────
    // Add PSR-16 “get” / “set” here:
    // ───────────────────────────────────────────────

    /**
     * PSR-16: return raw value or null.
     */
    public function get(string $key): mixed
    {
        $item = $this->getItem($key);
        return $item->isHit() ? $item->get() : null;
    }

    /**
     * PSR-16: store value, $ttl seconds.
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
