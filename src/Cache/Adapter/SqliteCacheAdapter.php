<?php

declare(strict_types=1);

namespace Infocyph\InterMix\Cache\Adapter;

use Countable;
use Iterator;
use PDO;
use Psr\Cache\CacheItemInterface;
use Psr\Cache\CacheItemPoolInterface;
use Infocyph\InterMix\Cache\Item\SqliteCacheItem;
use Infocyph\InterMix\Serializer\ValueSerializer;
use Infocyph\InterMix\Exceptions\CacheInvalidArgumentException;

/**
 * PSR-6 pool implemented with a single-file SQLite database.
 *
 *   • one table: cache(key TEXT PRIMARY KEY, value BLOB, expires INT)
 *   • uses ValueSerializer for objects/closures/resources
 *   • deferred queue obeys commit()
 *   • iterator & countable use SELECT queries
 */
class SqliteCacheAdapter implements CacheItemPoolInterface, Iterator, Countable
{
    /* ── state ────────────────────────────────────────────────────── */
    private readonly PDO    $pdo;
    private readonly string $ns;
    private array  $deferred  = [];   // key => SqliteCacheItem
    private array  $rowCache  = [];   // for iterator
    private int    $pos       = 0;

    public function __construct(
        string $namespace     = 'default',
        string $dbPath        = null,
    ) {
        $this->ns  = preg_replace('/[^A-Za-z0-9_\-]/', '_', $namespace);
        $file      = $dbPath ?: sys_get_temp_dir() . "/cache_{$this->ns}.sqlite";

        $this->pdo = new PDO('sqlite:' . $file);
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        /* create schema once */
        $this->pdo->exec(
            'CREATE TABLE IF NOT EXISTS cache (
                key TEXT PRIMARY KEY,
                value BLOB NOT NULL,
                expires INTEGER
             )'
        );
        $this->pdo->exec('CREATE INDEX IF NOT EXISTS exp_idx ON cache(expires)');
    }

    /* ── helpers ──────────────────────────────────────────────────── */
    private function validateKey(string $key): void
    {
        if ($key === '' || !preg_match('/^[A-Za-z0-9_.\-]+$/', $key)) {
            throw new CacheInvalidArgumentException(
                'Invalid SQLite cache key; allowed A-Z, a-z, 0-9, _, ., -'
            );
        }
    }

    /* ── fetch ------------------------------------------------------ */
    public function getItem(string $key): SqliteCacheItem
    {
        $this->validateKey($key);

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

        /* cache miss or expired */
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

    /* ── save / delete / clear ------------------------------------- */
    public function save(CacheItemInterface $item): bool
    {
        if (!$item instanceof SqliteCacheItem) {
            throw new CacheInvalidArgumentException('Wrong item class');
        }
        $blob = ValueSerializer::serialize($item);
        $exp  = $item->ttlSeconds() ? time() + $item->ttlSeconds() : null;

        $stmt = $this->pdo->prepare(
            'REPLACE INTO cache(key,value,expires) VALUES(:k,:v,:e)'
        );
        return $stmt->execute([
            ':k' => $item->getKey(),
            ':v' => $blob,
            ':e' => $exp,
        ]);
    }

    public function deleteItem(string $key): bool
    {
        $this->validateKey($key);
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

    /* ── deferred queue -------------------------------------------- */
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

    /* ── iterator --------------------------------------------------- */
    public function rewind(): void
    {
        $stmt = $this->pdo->query(
            'SELECT key,value FROM cache
             WHERE expires IS NULL OR expires > '.time()
        );
        $this->rowCache = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $this->pos = 0;
    }

    public function current(): mixed
    {
        $row = $this->rowCache[$this->pos] ?? null;
        if (!$row) {
            return null;
        }
        /** @var SqliteCacheItem $it */
        $it = ValueSerializer::unserialize($row['value']);
        return $it->get();
    }

    public function key(): mixed
    {
        return $this->rowCache[$this->pos]['key'] ?? null;
    }

    public function next(): void
    {
        $this->pos++;
    }
    public function valid(): bool
    {
        return isset($this->rowCache[$this->pos]);
    }

    /* ── countable -------------------------------------------------- */
    public function count(): int
    {
        return (int) $this->pdo->query(
            'SELECT COUNT(*) FROM cache
             WHERE expires IS NULL OR expires > '.time()
        )->fetchColumn();
    }

    /* ── item callbacks -------------------------------------------- */
    public function internalPersist(SqliteCacheItem $i): bool
    {
        return $this->save($i);
    }
    public function internalQueue(SqliteCacheItem $i): bool
    {
        return $this->saveDeferred($i);
    }
}
