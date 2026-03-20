<?php

declare(strict_types=1);

namespace Infocyph\InterMix\Cache\Adapter;

use Infocyph\InterMix\Cache\Item\MemCacheItem;
use Infocyph\InterMix\Exceptions\CacheInvalidArgumentException;
use Memcached;
use Psr\Cache\CacheItemInterface;
use RuntimeException;

class MemCacheAdapter extends AbstractCacheAdapter
{
    private readonly Memcached $mc;
    private readonly string $ns;
    private array $knownKeys = [];

    public function __construct(
        string $namespace = 'default',
        array $servers = [['127.0.0.1', 11211, 0]],
        ?Memcached $client = null,
    ) {
        if (!class_exists(Memcached::class)) {
            throw new RuntimeException('Memcached extension not loaded');
        }

        $this->ns = sanitize_cache_ns($namespace);
        $this->mc = $client ?? new Memcached();
        if (!$client) {
            $this->mc->addServers($servers);
        }
    }

    public function clear(): bool
    {
        $this->mc->flush();
        $this->deferred = [];
        $this->knownKeys = [];
        return true;
    }

    public function count(): int
    {
        return count($this->fetchKeys());
    }

    public function deleteItem(string $key): bool
    {
        $this->mc->delete($this->map($key));
        unset($this->knownKeys[$key]);
        return true;
    }

    public function deleteItems(array $keys): bool
    {
        foreach ($keys as $k) {
            $this->deleteItem($k);
        }
        return true;
    }

    public function getItem(string $key): MemCacheItem
    {
        $raw = $this->mc->get($this->map($key));
        if ($this->mc->getResultCode() === Memcached::RES_SUCCESS && is_string($raw)) {
            $record = CachePayloadCodec::decode($raw);
            if ($record !== null && !CachePayloadCodec::isExpired($record['expires'])) {
                return new MemCacheItem(
                    $this,
                    $key,
                    $record['value'],
                    true,
                    CachePayloadCodec::toDateTime($record['expires']),
                );
            }
            $this->mc->delete($this->map($key));
            unset($this->knownKeys[$key]);
        }
        return new MemCacheItem($this, $key);
    }

    public function hasItem(string $key): bool
    {
        $this->mc->get($this->map($key));
        return $this->mc->getResultCode() === \Memcached::RES_SUCCESS;
    }

    public function multiFetch(array $keys): array
    {
        if ($keys === []) {
            return [];
        }

        $prefixed = array_map($this->map(...), $keys);
        $raw = $this->mc->getMulti($prefixed, Memcached::GET_PRESERVE_ORDER) ?: [];

        $items = [];
        foreach ($keys as $k) {
            $p = $this->map($k);
            if (isset($raw[$p])) {
                $record = CachePayloadCodec::decode((string)$raw[$p]);
                if ($record !== null && !CachePayloadCodec::isExpired($record['expires'])) {
                    $items[$k] = new MemCacheItem(
                        $this,
                        $k,
                        $record['value'],
                        true,
                        CachePayloadCodec::toDateTime($record['expires']),
                    );
                    continue;
                }
                $this->mc->delete($p);
                unset($this->knownKeys[$k]);
            }
            $items[$k] = new MemCacheItem($this, $k);
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
            $this->mc->delete($this->map($item->getKey()));
            unset($this->knownKeys[$item->getKey()]);
            return true;
        }

        $blob = CachePayloadCodec::encode($item->get(), $expires['expiresAt']);
        $ok = $this->mc->set($this->map($item->getKey()), $blob, $ttl ?? 0);
        if ($ok) {
            $this->knownKeys[$item->getKey()] = true;
        }
        return $ok;
    }

    protected function supportsItem(CacheItemInterface $item): bool
    {
        return $item instanceof MemCacheItem;
    }

    private function fastKnownKeys(): array
    {
        return $this->knownKeys ? array_keys($this->knownKeys) : [];
    }

    private function fetchKeys(): array
    {
        if ($quick = $this->fastKnownKeys()) {
            return $quick;
        }

        $pref = $this->ns . ':';
        if ($keys = $this->keysFromGetAll($pref)) {
            return $keys;
        }
        return $this->keysFromSlabDump($pref);
    }

    private function keysFromGetAll(string $pref): array
    {
        $all = $this->mc->getAllKeys();
        if (!is_array($all)) {
            return [];
        }
        return $this->stripNamespace($all, $pref);
    }

    private function keysFromSlabDump(string $pref): array
    {
        $out = [];
        $seen = [];
        $stats = $this->mc->getStats('items');
        foreach ($stats as $server => $items) {
            foreach ($items as $name => $value) {
                if (!preg_match('/items:(\d+):number/', (string)$name, $m)) {
                    continue;
                }
                $slabId = (int)$m[1];
                $dump = $this->mc->getStats("cachedump $slabId 0");
                if (!isset($dump[$server])) {
                    continue;
                }

                foreach ($this->stripNamespace(array_keys($dump[$server]), $pref) as $key) {
                    if (isset($seen[$key])) {
                        continue;
                    }
                    $seen[$key] = true;
                    $out[] = $key;
                }
            }
        }
        return $out;
    }

    private function map(string $key): string
    {
        return $this->ns . ':' . $key;
    }

    private function stripNamespace(array $fullKeys, string $pref): array
    {
        return array_values(array_map(
            fn (string $k) => substr($k, strlen($pref)),
            array_filter($fullKeys, fn (string $k) => str_starts_with($k, $pref))
        ));
    }
}
