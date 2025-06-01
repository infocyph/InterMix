<?php

declare(strict_types=1);

namespace Infocyph\InterMix\Cache\Adapter;

use Countable;
use RuntimeException;
use Psr\Cache\CacheItemInterface;
use Psr\Cache\CacheItemPoolInterface;
use Infocyph\InterMix\Cache\Item\ApcuCacheItem;
use Infocyph\InterMix\Serializer\ValueSerializer;
use Infocyph\InterMix\Exceptions\CacheInvalidArgumentException;

class ApcuCacheAdapter implements CacheItemPoolInterface, Countable
{
    private readonly string $ns;
    private array $deferred = [];

    public function __construct(string $namespace = 'default')
    {
        if (!extension_loaded('apcu') || !apcu_enabled()) {
            throw new RuntimeException('APCu extension is not enabled');
        }
        $this->ns = preg_replace('/[^A-Za-z0-9_\-]/', '_', $namespace);
    }

    private function map(string $key): string
    {
        return $this->ns . ':' . $key;
    }

    public function multiFetch(array $keys): array
    {
        if ($keys === []) {
            return [];
        }
        $prefixed = array_map(fn ($k) => $this->map($k), $keys);
        $raw = apcu_fetch($prefixed);

        $items = [];
        foreach ($keys as $k) {
            $p = $this->map($k);
            if (array_key_exists($p, $raw)) {
                $val = ValueSerializer::unserialize($raw[$p]);
                if ($val instanceof CacheItemInterface) {
                    $val = $val->get();
                }
                $items[$k] = new ApcuCacheItem($this, $k, $val, true);
            } else {
                $items[$k] = new ApcuCacheItem($this, $k);
            }
        }
        return $items;
    }

    public function getItem(string $key): ApcuCacheItem
    {
        $apcuKey = $this->map($key);
        $success = false;
        $raw = apcu_fetch($apcuKey, $success);

        if ($success && is_string($raw)) {
            $item = ValueSerializer::unserialize($raw);
            if ($item instanceof ApcuCacheItem && $item->isHit()) {
                return $item;
            }
        }
        return new ApcuCacheItem($this, $key);
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

    public function deleteItem(string $key): bool
    {
        return apcu_delete($this->map($key));
    }

    public function deleteItems(array $keys): bool
    {
        $ok = true;
        foreach ($keys as $k) {
            $ok = $ok && $this->deleteItem($k);
        }
        return $ok;
    }

    public function clear(): bool
    {
        foreach ($this->listKeys() as $apcuKey) {
            apcu_delete($apcuKey);
        }
        $this->deferred = [];
        return true;
    }

    public function save(CacheItemInterface $item): bool
    {
        if (!$item instanceof ApcuCacheItem) {
            throw new CacheInvalidArgumentException('Wrong item type for ApcuCacheAdapter');
        }
        $blob = ValueSerializer::serialize($item);
        $ttl = $item->ttlSeconds();
        return apcu_store($this->map($item->getKey()), $blob, $ttl ?? 0);
    }

    public function saveDeferred(CacheItemInterface $item): bool
    {
        if (!$item instanceof ApcuCacheItem) {
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

    private function listKeys(): array
    {
        $info = apcu_cache_info();
        $pref = $this->ns . ':';
        $keys = [];
        foreach ($info['cache_list'] ?? [] as $entry) {
            if (isset($entry['info']) && str_starts_with((string)$entry['info'], $pref)) {
                $keys[] = $entry['info'];
            }
        }
        return $keys;
    }

    public function count(): int
    {
        return count($this->listKeys());
    }

    // ───────────────────────────────────────────────
    // Add these two methods to support PSR-16 fast‐path:
    // ───────────────────────────────────────────────

    /**
     * PSR-16 “get($key)”: return the raw value or null if miss.
     */
    public function get(string $key): mixed
    {
        $item = $this->getItem($key);
        return $item->isHit() ? $item->get() : null;
    }

    /**
     * PSR-16 “set($key, $value, $ttl)”: wrap into a CacheItem + save().
     */
    public function set(string $key, mixed $value, ?int $ttl = null): bool
    {
        $item = $this->getItem($key);
        $item->set($value)->expiresAfter($ttl);
        return $this->save($item);
    }


    /**
     * @param ApcuCacheItem $item The cache item to persist.
     *
     * @return bool TRUE if the item was successfully persisted, FALSE otherwise.
     * @internal
     * Persists a cache item in the cache pool.
     *
     * This method is called by the cache item when it is persisted
     * using the `save()` method. It is not intended to be called
     * directly.
     *
     */
    public function internalPersist(ApcuCacheItem $item): bool
    {
        return $this->save($item);
    }

    /**
     * Adds the given cache item to the internal deferred queue.
     *
     * This method enqueues the cache item for later persistence in
     * the cache pool. The item will not be saved immediately, but
     * will be stored when the commit() method is called.
     *
     * @param ApcuCacheItem $item The cache item to be queued for deferred saving.
     * @return bool True if the item was successfully queued, false otherwise.
     */
    public function internalQueue(ApcuCacheItem $item): bool
    {
        return $this->saveDeferred($item);
    }
}
