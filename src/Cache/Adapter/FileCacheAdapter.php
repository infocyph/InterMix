<?php

declare(strict_types=1);

namespace Infocyph\InterMix\Cache\Adapter;

use Countable;
use Infocyph\InterMix\Serializer\ValueSerializer;
use Psr\Cache\CacheItemInterface;
use Psr\Cache\CacheItemPoolInterface;
use Infocyph\InterMix\Cache\Item\FileCacheItem;
use Infocyph\InterMix\Exceptions\CacheInvalidArgumentException;
use RuntimeException;

class FileCacheAdapter implements CacheItemPoolInterface, Countable
{
    private string $dir;
    private array $deferred = [];

    public function __construct(string $namespace = 'default', ?string $baseDir = null)
    {
        $this->createDirectory($namespace, $baseDir);
    }

    public function setNamespaceAndDirectory(string $namespace, ?string $baseDir = null): void
    {
        $this->createDirectory($namespace, $baseDir);
        $this->deferred = [];
    }

    private function createDirectory(string $ns, ?string $base): void
    {
        $base = rtrim($base ?? sys_get_temp_dir(), DIRECTORY_SEPARATOR);
        $ns = preg_replace('/[^A-Za-z0-9_\-]/', '_', $ns);
        $this->dir = "$base/cache_$ns/";

        if (file_exists($this->dir) && !is_dir($this->dir)) {
            throw new RuntimeException("'{$this->dir}' exists and is not a directory");
        }
        if (!is_dir($this->dir) && !@mkdir($this->dir, 0770, true)) {
            $err = error_get_last()['message'] ?? 'unknown error';
            throw new RuntimeException("Failed to create '{$this->dir}': {$err}");
        }
        if (!is_writable($this->dir)) {
            throw new RuntimeException("Cache directory '{$this->dir}' is not writable");
        }
    }

    private function fileFor(string $key): string
    {
        return $this->dir . hash('xxh128', $key) . '.cache';
    }

    public function getItem(string $key): CacheItemInterface
    {
        $file = $this->fileFor($key);

        if (is_file($file)) {
            $raw = file_get_contents($file);
            $item = ValueSerializer::unserialize($raw);

            if ($item instanceof FileCacheItem && $item->isHit()) {
                return $item;
            }
            @unlink($file);
        }

        return new FileCacheItem($this, $key);
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
        return @unlink($this->fileFor($key));
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
        $ok = true;
        foreach (glob("$this->dir*.cache") as $f) {
            $ok = $ok && @unlink($f);
        }
        $this->deferred = [];
        return $ok;
    }

    public function save(CacheItemInterface $item): bool
    {
        if (!$item instanceof FileCacheItem) {
            throw new CacheInvalidArgumentException('Invalid item type for FileCacheAdapter');
        }
        $blob = ValueSerializer::serialize($item);
        return (bool) file_put_contents($this->fileFor($item->getKey()), $blob, LOCK_EX);
    }

    public function saveDeferred(CacheItemInterface $item): bool
    {
        if (!$item instanceof FileCacheItem) {
            return false;
        }
        $this->deferred[$item->getKey()] = $item;
        return true;
    }

    public function commit(): bool
    {
        $ok = true;
        foreach ($this->deferred as $key => $item) {
            $ok = $ok && $this->save($item);
            unset($this->deferred[$key]);
        }
        return $ok;
    }

    public function count(): int
    {
        return count(glob("$this->dir*.cache"));
    }

    // ───────────────────────────────────────────────
    // Add PSR-16 “get” / “set” here:
    // ───────────────────────────────────────────────

    /**
     * PSR-16: return “raw value” or null if miss.
     */
    public function get(string $key): mixed
    {
        $item = $this->getItem($key);
        return $item->isHit() ? $item->get() : null;
    }

    /**
     * PSR-16: store value for $ttl seconds.
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
     * @param FileCacheItem $item The cache item to persist.
     *
     * @return bool TRUE if the item was successfully persisted, FALSE otherwise.
     * @internal
     */
    public function internalPersist(FileCacheItem $item): bool
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
     * @param FileCacheItem $item The cache item to be queued for deferred saving.
     * @return bool True if the item was successfully queued, false otherwise.
     */
    public function internalQueue(FileCacheItem $item): bool
    {
        return $this->saveDeferred($item);
    }
}
