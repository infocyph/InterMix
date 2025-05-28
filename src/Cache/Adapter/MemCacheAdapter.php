<?php

declare(strict_types=1);

namespace Infocyph\InterMix\Cache\Adapter;

use Countable;
use Iterator;
use Memcached;
use RuntimeException;
use Psr\Cache\CacheItemInterface;
use Psr\Cache\CacheItemPoolInterface;
use Infocyph\InterMix\Cache\Item\MemCacheItem;
use Infocyph\InterMix\Serializer\ValueSerializer;
use Infocyph\InterMix\Exceptions\CacheInvalidArgumentException;

/**
 * PSR-6 pool powered by the Memcached extension.
 *
 *  – Uses ValueSerializer so closures/resources survive.
 *  – Deferred queue obeys commit().
 *  – Iterator/Countable enumerate keys via Memcached::getAllKeys()
 *    (requires memcached >= 1.6 and `memcached.use_sasl` OFF).
 */
class MemCacheAdapter implements CacheItemPoolInterface, Iterator, Countable
{
    /* ── state ─────────────────────────────────────────────────────── */
    private readonly Memcached $mc;
    private readonly string $ns;
    private array $deferred = [];      // key => MemCacheItem
    private array $keyList = [];      // iterator snapshot
    private int $pos = 0;
    /** keys inserted during this PHP run (k => true) */
    private array $knownKeys = [];


    /**
     * @param string $namespace cache prefix
     * @param array $servers list of [host, port, weight] triples
     *                           defaults to 127.0.0.1:11211
     * @param ?Memcached $client optional pre-configured Memcached instance
     */
    public function __construct(
        string $namespace = 'default',
        array $servers = [['127.0.0.1', 11211, 0]],
        ?Memcached $client = null,
    ) {
        if (!class_exists(Memcached::class)) {
            throw new RuntimeException('Memcached extension not loaded');
        }

        $this->ns = preg_replace('/[^A-Za-z0-9_\-]/', '_', $namespace);
        $this->mc = $client ?? new Memcached();
        if (!$client) {
            $this->mc->addServers($servers);
        }
    }

    /* ── helpers ──────────────────────────────────────────────────── */
    private function k(string $key): string
    {
        return $this->ns . ':' . $key;
    }

    /* ── fetch ------------------------------------------------------ */
    public function getItem(string $key): MemCacheItem
    {
        $raw = $this->mc->get($this->k($key));
        if ($this->mc->getResultCode() === Memcached::RES_SUCCESS && is_string($raw)) {
            $item = ValueSerializer::unserialize($raw);
            if ($item instanceof MemCacheItem && $item->isHit()) {
                return $item;
            }
        }
        return new MemCacheItem($this, $key);        // cache miss
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

    /* ── delete / clear -------------------------------------------- */
    public function deleteItem(string $key): bool
    {
        $this->mc->delete($this->k($key));
        $this->unregister($key);
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
        $this->mc->flush();
        $this->deferred = [];
        $this->knownKeys  = [];
        return true;
    }

    /* ── save / deferred / commit ---------------------------------- */
    public function save(CacheItemInterface $item): bool
    {
        if (!$item instanceof MemCacheItem) {
            throw new CacheInvalidArgumentException('Wrong item class');
        }
        $blob = ValueSerializer::serialize($item);
        $ttl = $item->ttlSeconds();
        $ok = $this->mc->set($this->k($item->getKey()), $blob, $ttl ?? 0);
        if ($ok) {
            $this->register($item->getKey());
        }
        return $ok;
    }

    public function saveDeferred(CacheItemInterface $item): bool
    {
        if (!$item instanceof MemCacheItem) {
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

    /* ── Iterator & Countable -------------------------------------- */
    /* ---------- public wrapper ------------------------------------------------ */

    private function fetchKeys(): array
    {
        if ($quick = $this->fastKnownKeys()) {                 // 1
            return $quick;
        }

        $pref = $this->ns . ':';

        if ($keys = $this->keysFromGetAll($pref)) {            // 2
            return $keys;
        }

        return $this->keysFromSlabDump($pref);                 // 3
    }

    /* ---------- 1) in-process registry --------------------------------------- */

    private function fastKnownKeys(): array
    {
        return $this->knownKeys ? array_keys($this->knownKeys) : [];
    }

    /* ---------- 2) Memcached::getAllKeys() ----------------------------------- */

    private function keysFromGetAll(string $pref): array
    {
        $all = $this->mc->getAllKeys();                // false if disabled
        if (!is_array($all)) {
            return [];
        }
        return $this->stripNamespace($all, $pref);
    }

    /* ---------- 3) slab walk fallback ---------------------------------------- */

    private function keysFromSlabDump(string $pref): array
    {
        $out   = [];
        $stats = $this->mc->getStats('items');

        foreach ($stats as $server => $items) {
            foreach ($items as $name => $_) {
                if (!preg_match('/items:(\d+):number/', (string) $name, $m)) {
                    continue;
                }
                $slabId = (int) $m[1];
                $dump   = $this->mc->getStats("cachedump $slabId 0");
                if (!isset($dump[$server])) {
                    continue;
                }
                $out = array_merge(
                    $out,
                    $this->stripNamespace(array_keys($dump[$server]), $pref)
                );
            }
        }
        return array_values(array_unique($out));
    }

    /* ---------- helper ------------------------------------------------------- */

    private function stripNamespace(array $fullKeys, string $pref): array
    {
        return array_values(array_map(
            fn (string $k) => substr($k, strlen($pref)),
            array_filter($fullKeys, fn (string $k) => str_starts_with($k, $pref))
        ));
    }


    public function rewind(): void
    {
        $this->keyList = $this->fetchKeys();
        $this->pos = 0;
    }

    public function current(): mixed
    {
        $item = $this->getItem($this->keyList[$this->pos]);
        return $item->get();
    }

    public function key(): mixed
    {
        return $this->keyList[$this->pos] ?? null;
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
        return count($this->fetchKeys());
    }

    /* ── internal helpers for item --------------------------------- */
    public function internalPersist(MemCacheItem $i): bool
    {
        return $this->save($i);
    }

    public function internalQueue(MemCacheItem $i): bool
    {
        return $this->saveDeferred($i);
    }

    private function register(string $key): void
    {
        $this->knownKeys[$key] = true;
    }
    private function unregister(string $key): void
    {
        unset($this->knownKeys[$key]);
    }

}
