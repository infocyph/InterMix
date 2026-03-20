<?php

declare(strict_types=1);

namespace Infocyph\InterMix\Cache\Adapter;

use Infocyph\InterMix\Cache\Item\RedisCacheItem;
use Infocyph\InterMix\Exceptions\CacheInvalidArgumentException;
use Psr\Cache\CacheItemInterface;
use Redis;
use RuntimeException;

class RedisCacheAdapter extends AbstractCacheAdapter
{
    private readonly string $ns;
    private readonly Redis $redis;

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

    public function count(): int
    {
        $iter = null;
        $count = 0;
        while ($keys = $this->redis->scan($iter, $this->ns . ':*', 1000)) {
            $count += count($keys);
        }
        return $count;
    }

    public function deleteItem(string $key): bool
    {
        return (bool) $this->redis->del($this->map($key));
    }

    public function deleteItems(array $keys): bool
    {
        $full = array_map($this->map(...), $keys);
        return $this->redis->del($full) === count($keys);
    }

    public function getItem(string $key): RedisCacheItem
    {
        $raw = $this->redis->get($this->map($key));
        if (is_string($raw)) {
            $record = CachePayloadCodec::decode($raw);
            if ($record !== null && !CachePayloadCodec::isExpired($record['expires'])) {
                return new RedisCacheItem(
                    $this,
                    $key,
                    $record['value'],
                    true,
                    CachePayloadCodec::toDateTime($record['expires']),
                );
            }
            $this->redis->del($this->map($key));
        }
        return new RedisCacheItem($this, $key);
    }

    public function hasItem(string $key): bool
    {
        return $this->redis->exists($this->map($key)) === 1;
    }

    public function multiFetch(array $keys): array
    {
        if ($keys === []) {
            return [];
        }

        $prefixed = array_map($this->map(...), $keys);
        $rawVals = $this->redis->mget($prefixed);

        $items = [];
        foreach ($keys as $idx => $k) {
            $v = $rawVals[$idx];
            if ($v !== null && $v !== false) {
                $record = CachePayloadCodec::decode((string)$v);
                if ($record !== null && !CachePayloadCodec::isExpired($record['expires'])) {
                    $items[$k] = new RedisCacheItem(
                        $this,
                        $k,
                        $record['value'],
                        true,
                        CachePayloadCodec::toDateTime($record['expires']),
                    );
                    continue;
                }
                $this->redis->del($this->map($k));
            }
            $items[$k] = new RedisCacheItem($this, $k);
        }
        return $items;
    }

    public function save(CacheItemInterface $item): bool
    {
        if (!$this->supportsItem($item)) {
            throw new CacheInvalidArgumentException('RedisCacheAdapter expects RedisCacheItem');
        }

        $expires = CachePayloadCodec::expirationFromItem($item);
        $ttl = $expires['ttl'];
        if ($ttl === 0) {
            $this->redis->del($this->map($item->getKey()));
            return true;
        }

        $blob = CachePayloadCodec::encode($item->get(), $expires['expiresAt']);
        return $ttl === null
            ? $this->redis->set($this->map($item->getKey()), $blob)
            : $this->redis->setex($this->map($item->getKey()), max(1, $ttl), $blob);
    }

    protected function supportsItem(CacheItemInterface $item): bool
    {
        return $item instanceof RedisCacheItem;
    }

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

    private function map(string $key): string
    {
        return $this->ns . ':' . $key;
    }
}
