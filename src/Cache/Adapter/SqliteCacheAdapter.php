<?php

declare(strict_types=1);

namespace Infocyph\InterMix\Cache\Adapter;

use Infocyph\InterMix\Cache\Item\SqliteCacheItem;
use Infocyph\InterMix\Exceptions\CacheInvalidArgumentException;
use PDO;
use Psr\Cache\CacheItemInterface;

class SqliteCacheAdapter extends AbstractCacheAdapter
{
    private readonly string $ns;
    private readonly PDO $pdo;

    public function __construct(
        string $namespace = 'default',
        ?string $dbPath = null,
    ) {
        $this->ns = sanitize_cache_ns($namespace);
        $file = $dbPath ?: sys_get_temp_dir() . "/cache_$this->ns.sqlite";

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

    public function clear(): bool
    {
        $this->pdo->exec('DELETE FROM cache');
        $this->deferred = [];
        return true;
    }

    public function count(): int
    {
        return (int) $this->pdo->query(
            'SELECT COUNT(*) FROM cache WHERE expires IS NULL OR expires > ' . time()
        )->fetchColumn();
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

    public function getItem(string $key): SqliteCacheItem
    {
        $stmt = $this->pdo->prepare(
            'SELECT value, expires FROM cache WHERE key = :k LIMIT 1'
        );
        $stmt->execute([':k' => $key]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        $now = time();
        if ($row && (!$row['expires'] || $row['expires'] > $now)) {
            $record = CachePayloadCodec::decode($row['value']);
            $expiresAt = is_numeric($row['expires']) ? (int)$row['expires'] : null;
            if ($record !== null) {
                $expiresAt = $record['expires'] ?? $expiresAt;
                if (!CachePayloadCodec::isExpired($expiresAt, $now)) {
                    return new SqliteCacheItem(
                        $this,
                        $key,
                        $record['value'],
                        true,
                        CachePayloadCodec::toDateTime($expiresAt),
                    );
                }
            }
        }

        $this->pdo->prepare('DELETE FROM cache WHERE key = :k')->execute([':k' => $key]);
        return new SqliteCacheItem($this, $key);
    }

    public function hasItem(string $key): bool
    {
        return $this->getItem($key)->isHit();
    }

    public function multiFetch(array $keys): array
    {
        if ($keys === []) {
            return [];
        }

        $rows = $this->fetchRowsByKeys($keys);
        $items = [];
        $now = time();

        foreach ($keys as $k) {
            $items[$k] = $this->buildFetchedItem($k, $rows, $now);
        }

        return $items;
    }

    public function save(CacheItemInterface $item): bool
    {
        if (!$this->supportsItem($item)) {
            throw new CacheInvalidArgumentException('Wrong item class');
        }

        $expires = CachePayloadCodec::expirationFromItem($item);
        $ttl = $expires['ttl'];
        if ($ttl === 0) {
            return $this->deleteItem($item->getKey());
        }

        $blob = CachePayloadCodec::encode($item->get(), $expires['expiresAt']);

        $stmt = $this->pdo->prepare(
            'REPLACE INTO cache(key, value, expires) VALUES(:k, :v, :e)'
        );
        return $stmt->execute([
            ':k' => $item->getKey(),
            ':v' => $blob,
            ':e' => $expires['expiresAt'],
        ]);
    }

    protected function supportsItem(CacheItemInterface $item): bool
    {
        return $item instanceof SqliteCacheItem;
    }

    private function buildFetchedItem(
        string $key,
        array $rows,
        int $now,
    ): SqliteCacheItem {
        if (isset($rows[$key])) {
            $item = $this->hydrateFetchedItem($key, $rows[$key], $now);
            if ($item !== null) {
                return $item;
            }

            $this->pdo->prepare('DELETE FROM cache WHERE key = ?')->execute([$key]);
        }

        return new SqliteCacheItem($this, $key);
    }

    /**
     * @return array<string,array{value:string,expires:int|null}>
     */
    private function fetchRowsByKeys(array $keys): array
    {
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

        return $rows;
    }

    private function hydrateFetchedItem(
        string $key,
        array $row,
        int $now,
    ): ?SqliteCacheItem {
        if ($row['expires'] !== null && $row['expires'] <= $now) {
            return null;
        }

        $record = CachePayloadCodec::decode($row['value']);
        $expiresAt = $row['expires'];

        if ($record === null) {
            return null;
        }

        $expiresAt = $record['expires'] ?? $expiresAt;
        if (CachePayloadCodec::isExpired($expiresAt, $now)) {
            return null;
        }

        return new SqliteCacheItem(
            $this,
            $key,
            $record['value'],
            true,
            CachePayloadCodec::toDateTime($expiresAt),
        );
    }
}
