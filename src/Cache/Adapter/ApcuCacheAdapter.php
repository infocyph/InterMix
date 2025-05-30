<?php

declare(strict_types=1);

namespace Infocyph\InterMix\Cache\Adapter;

use Countable;
use Iterator;
use RuntimeException;
use Psr\Cache\CacheItemInterface;
use Psr\Cache\CacheItemPoolInterface;
use Infocyph\InterMix\Cache\Item\ApcuCacheItem;
use Infocyph\InterMix\Serializer\ValueSerializer;
use Infocyph\InterMix\Exceptions\CacheInvalidArgumentException;

class ApcuCacheAdapter implements CacheItemPoolInterface, Iterator, Countable
{
    /* ── state ───────────────────────────────────────────────────────── */
    private readonly string $ns;
    private array  $deferred = [];      // key => ApcuCacheItem
    private array  $keyList  = [];      // iterator snapshot
    private int    $pos      = 0;

    public function __construct(string $namespace = 'default')
    {
        if (!extension_loaded('apcu') || !apcu_enabled()) {
            throw new RuntimeException('APCu extension is not enabled');
        }
        $this->ns = preg_replace('/[^A-Za-z0-9_\-]/', '_', $namespace);
    }

    /* ── helpers ─────────────────────────────────────────────────────── */
    private function map(string $key): string
    {
        return $this->ns . ':' . $key;
    }

    /* ── PSR-6 fetch ─────────────────────────────────────────────────── */
    public function multiFetch(array $keys): array
    {
        if ($keys === []) {
            return [];
        }
        $prefixed = array_map(fn ($k) => $this->ns . ':' . $k, $keys);
        $raw      = apcu_fetch($prefixed);

        $items = [];
        foreach ($keys as $k) {
            $p = $this->ns . ':' . $k;
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
        $raw     = apcu_fetch($apcuKey, $success);

        if ($success && is_string($raw)) {
            $item = ValueSerializer::unserialize($raw);
            if ($item instanceof ApcuCacheItem && $item->isHit()) {
                return $item;
            }
        }
        return new ApcuCacheItem($this, $key); // cache miss
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

    /* ── delete / clear ──────────────────────────────────────────────── */
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

    /* ── save / deferred / commit ────────────────────────────────────── */
    public function save(CacheItemInterface $item): bool
    {
        if (!$item instanceof ApcuCacheItem) {
            throw new CacheInvalidArgumentException('Wrong item type for ApcuCacheAdapter');
        }
        $blob = ValueSerializer::serialize($item);
        $ttl  = $item->ttlSeconds();
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

    /* ── Iterator & Countable ────────────────────────────────────────── */
    private function listKeys(): array
    {
        $info = apcu_cache_info();
        $pref = $this->ns . ':';
        $keys = [];
        foreach ($info['cache_list'] ?? [] as $entry) {
            if (isset($entry['info']) && str_starts_with((string) $entry['info'], $pref)) {
                $keys[] = $entry['info'];
            }
        }
        return $keys;
    }

    public function rewind(): void
    {
        $this->keyList = $this->listKeys();
        $this->pos     = 0;
    }

    public function current(): mixed
    {
        $item = $this->loadItem($this->keyList[$this->pos] ?? null);
        return $item?->get();
    }

    public function key(): mixed
    {
        $item = $this->loadItem($this->keyList[$this->pos] ?? null);
        return $item?->getKey();
    }

    public function next(): void
    {
        $this->pos++;
    }
    public function valid(): bool
    {
        return isset($this->keyList[$this->pos]);
    }
    public function count(): int
    {
        return count($this->listKeys());
    }

    private function loadItem(?string $apcuKey): ?ApcuCacheItem
    {
        if (!$apcuKey) {
            return null;
        }
        $raw = apcu_fetch($apcuKey);
        return $raw ? ValueSerializer::unserialize($raw) : null;
    }

    /* ── helpers for ApcuCacheItem ───────────────────────────────────── */
    public function internalPersist(ApcuCacheItem $item): bool
    {
        return $this->save($item);
    }
    public function internalQueue(ApcuCacheItem $item): bool
    {
        return $this->saveDeferred($item);
    }
}
