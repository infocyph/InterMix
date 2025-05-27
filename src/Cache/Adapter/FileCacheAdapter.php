<?php

// src/Cache/Adapter/FileCacheAdapter.php

declare(strict_types=1);

namespace Infocyph\InterMix\Cache\Adapter;

use Countable;
use Infocyph\InterMix\Serializer\ValueSerializer;
use Iterator;
use Psr\Cache\CacheItemInterface;
use Psr\Cache\CacheItemPoolInterface;
use Infocyph\InterMix\Cache\Item\FileCacheItem;
use Infocyph\InterMix\Exceptions\CacheInvalidArgumentException;
use RuntimeException;

/**
 * Filesystem-backed PSR-6 adapter.
 *
 *  • full ValueSerializer support (closures, objects, resources …)
 *  • deferred queue obeys commit()
 *  • Iterator exposes original keys and values
 */
class FileCacheAdapter implements CacheItemPoolInterface, Iterator, Countable
{
    /* -----------------------------------------------------------------
     *  Internal state
     * ----------------------------------------------------------------*/
    private string $dir;                // cache directory
    private array $deferred = [];      // [key => FileCacheItem]
    private array $fileList = [];      // iterator: absolute cache files
    private int $pos = 0;

    /* -----------------------------------------------------------------
     *  Construction
     * ----------------------------------------------------------------*/
    public function __construct(string $namespace = 'default', ?string $baseDir = null)
    {
        $this->createDirectory($namespace, $baseDir);
    }

    /* -----------------------------------------------------------------
 *  Runtime switch of namespace and/or base directory
 * ----------------------------------------------------------------*/
    public function setNamespaceAndDirectory(string $namespace, ?string $baseDir = null): void
    {
        // Reset internal state to the new location
        $this->createDirectory($namespace, $baseDir);

        // Clear any iterator snapshot and deferred queue
        $this->fileList = [];
        $this->pos = 0;
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

    /* -----------------------------------------------------------------
     *  Helper: map key → filename
     * ----------------------------------------------------------------*/
    private function fileFor(string $key): string
    {
        return $this->dir . hash('sha256', $key) . '.cache';
    }

    /* -----------------------------------------------------------------
     *  PSR-6: fetch
     * ----------------------------------------------------------------*/
    public function getItem(string $key): CacheItemInterface
    {
        $file = $this->fileFor($key);

        if (is_file($file)) {
            $raw = file_get_contents($file);
            $item = ValueSerializer::unserialize($raw);

            if ($item instanceof FileCacheItem && $item->isHit()) {
                return $item;
            }
            @unlink($file); // expired or corrupted
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

    /* -----------------------------------------------------------------
     *  PSR-6: deletion & clear
     * ----------------------------------------------------------------*/
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
        foreach (glob("{$this->dir}*.cache") as $f) {
            $ok = $ok && @unlink($f);
        }
        $this->deferred = [];
        return $ok;
    }

    /* -----------------------------------------------------------------
     *  PSR-6: save / saveDeferred / commit
     * ----------------------------------------------------------------*/
    public function save(CacheItemInterface $item): bool
    {
        if (!$item instanceof FileCacheItem) {
            throw new CacheInvalidArgumentException('Invalid item type for FileCacheAdapter');
        }

        $blob = ValueSerializer::serialize($item);
        return (bool)file_put_contents($this->fileFor($item->getKey()), $blob, LOCK_EX);
    }

    public function saveDeferred(CacheItemInterface $item): bool
    {
        if (!$item instanceof FileCacheItem) {
            return false;
        }
        $this->deferred[$item->getKey()] = $item;
        return true; // queued
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

    /* -----------------------------------------------------------------
     *  Iterator implementation (keys & values)
     * ----------------------------------------------------------------*/
    public function rewind(): void
    {
        $this->fileList = glob("{$this->dir}*.cache", GLOB_NOSORT);
        $this->pos = 0;
    }

    public function current(): mixed
    {
        $item = $this->loadItem($this->fileList[$this->pos]);
        return $item->get();
    }

    public function key(): mixed
    {
        $item = $this->loadItem($this->fileList[$this->pos]);
        return $item->getKey(); // original user key
    }

    public function next(): void
    {
        $this->pos++;
    }

    public function valid(): bool
    {
        return isset($this->fileList[$this->pos]);
    }

    /* -----------------------------------------------------------------
     *  Countable
     * ----------------------------------------------------------------*/
    public function count(): int
    {
        return count(glob("{$this->dir}*.cache"));
    }

    /* -----------------------------------------------------------------
     *  Support methods
     * ----------------------------------------------------------------*/
    private function loadItem(string $file): FileCacheItem
    {
        $raw = file_get_contents($file);
        /** @var FileCacheItem $item */
        $item = ValueSerializer::unserialize($raw);
        return $item;
    }

    /* -----------------------------------------------------------------
     *  Extra helpers used by FileCacheItem
     * ----------------------------------------------------------------*/
    public function internalPersist(FileCacheItem $item): bool
    {
        return $this->save($item);
    }

    public function internalQueue(FileCacheItem $item): bool
    {
        return $this->saveDeferred($item);
    }
}
