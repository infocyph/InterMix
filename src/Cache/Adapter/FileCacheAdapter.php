<?php

// src/Cache/Adapter/FileCacheAdapter.php

declare(strict_types=1);

namespace Infocyph\InterMix\Cache\Adapter;

use Countable;
use Infocyph\InterMix\Serializer\ValueSerializer;
use Psr\Cache\CacheItemInterface;
use Psr\Cache\CacheItemPoolInterface;
use Infocyph\InterMix\Cache\Item\FileCacheItem;
use Infocyph\InterMix\Exceptions\CacheInvalidArgumentException;
use Psr\Cache\InvalidArgumentException;
use RuntimeException;

class FileCacheAdapter implements CacheItemPoolInterface, Countable
{
    private string $dir;
    private array $deferred = [];


    /**
     * FileCacheAdapter constructor.
     *
     * @param string $namespace The prefix for every cache key.
     * @param string|null $baseDir The directory to store cache files in. If not specified,
     *                             the system temporary directory will be used.
     */
    public function __construct(string $namespace = 'default', ?string $baseDir = null)
    {
        $this->createDirectory($namespace, $baseDir);
    }


    /**
     * Change the namespace and/or directory for the pool at runtime.
     *
     * This implementation discards any existing iterator snapshot and deferred
     * queue, as changing the namespace or directory would make them invalid.
     *
     * @param string $namespace The new namespace.
     * @param string|null $baseDir The new base directory, or null to use the
     *                                default (system temporary directory).
     */
    public function setNamespaceAndDirectory(string $namespace, ?string $baseDir = null): void
    {
        $this->createDirectory($namespace, $baseDir);
        $this->deferred = [];
    }


    /**
     * Creates a cache directory based on the given namespace and base directory.
     *
     * This method constructs a directory path using the provided namespace and
     * base directory, ensuring the directory exists and is writable. If the base
     * directory is not specified, the system temporary directory is used by default.
     * The namespace is sanitized to allow only alphanumeric characters, underscores,
     * and hyphens. If the directory already exists and is not a directory, or if
     * the directory cannot be created or is not writable, a RuntimeException is thrown.
     *
     * @param string $ns The namespace to use in the directory path.
     * @param string|null $base The base directory to store cache files. If null,
     *                          the system temporary directory is used.
     *
     * @throws RuntimeException If the directory cannot be created or is not writable.
     */
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


    /**
     * Generates the file path for a given cache key.
     *
     * This method constructs the file path by hashing the provided key
     * and appending a '.cache' extension. The file is
     * located within the cache directory specific to the current namespace.
     *
     * @param string $key The cache key for which to generate the file path.
     * @return string The full file path for the cache item.
     */
    private function fileFor(string $key): string
    {
        return $this->dir . hash('xxh128', $key) . '.cache';
    }


    /**
     * Retrieves a cache item by its key.
     *
     * This method attempts to fetch the cache item associated with the given key
     * from the file-based cache. If the item is found and is a cache hit, it is
     * returned. Otherwise, a new FileCacheItem is returned indicating a cache
     * miss.
     *
     * @param string $key The key of the cache item to retrieve.
     * @return CacheItemInterface The cache item associated with the specified key.
     */
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

    /**
     * Retrieves multiple cache items from the cache.
     *
     * Implementations may choose to use an adapter-specific method (e.g.
     * `multiFetch`) if available, or fall back to calling `getItem` for each
     * key.
     *
     * If the input array is empty, an empty iterator is returned.
     *
     * @param array $keys Cache keys to retrieve.
     *
     * @return iterable An iterator over CacheItemInterface objects.
     *
     */
    public function getItems(array $keys = []): iterable
    {
        foreach ($keys as $k) {
            yield $k => $this->getItem($k);
        }
    }

    /**
     * Determines whether a cache item exists for the given key.
     *
     * This method validates the key format and delegates the existence check
     * to the underlying adapter.
     *
     * @param string $key The key of the cache item to check.
     * @return bool Returns true if the cache item exists, false otherwise.
     * @throws CacheInvalidArgumentException if the key is invalid.
     */
    public function hasItem(string $key): bool
    {
        return $this->getItem($key)->isHit();
    }

    /**
     * Deletes a cache item by its key.
     *
     * This method attempts to remove the cache item associated with the given key
     * from the filesystem. It returns true if the item was successfully deleted,
     * false otherwise.
     *
     * @param string $key The key of the cache item to delete.
     *
     * @return bool True if the item was successfully deleted, false otherwise.
     * @throws CacheInvalidArgumentException if the key is invalid.
     */
    public function deleteItem(string $key): bool
    {
        return @unlink($this->fileFor($key));
    }

    /**
     * Deletes multiple cache items by their keys.
     *
     * This method attempts to remove each cache item associated with the given keys from the filesystem.
     * It returns true if all items were successfully deleted, or false if any deletion fails.
     *
     * @param array $keys An array of keys for the cache items to delete.
     *
     * @return bool True if all items were successfully deleted, false otherwise.
     * @throws InvalidArgumentException
     */
    public function deleteItems(array $keys): bool
    {
        $ok = true;
        foreach ($keys as $k) {
            $ok = $ok && $this->deleteItem($k);
        }
        return $ok;
    }

    /**
     * Remove all items from the cache.
     *
     * {@inheritDoc}
     *
     * @return bool
     */
    public function clear(): bool
    {
        $ok = true;
        foreach (glob("$this->dir*.cache") as $f) {
            $ok = $ok && @unlink($f);
        }
        $this->deferred = [];
        return $ok;
    }


    /**
     * Saves the cache item to the filesystem.
     *
     * @param CacheItemInterface $item The cache item to save.
     *
     * @return bool TRUE if the item was successfully saved, FALSE otherwise.
     *
     * @throws CacheInvalidArgumentException If the given item is not an instance
     *   of FileCacheItem.
     */
    public function save(CacheItemInterface $item): bool
    {
        if (!$item instanceof FileCacheItem) {
            throw new CacheInvalidArgumentException('Invalid item type for FileCacheAdapter');
        }

        $blob = ValueSerializer::serialize($item);
        return (bool)file_put_contents($this->fileFor($item->getKey()), $blob, LOCK_EX);
    }

    /**
     * Queues the given cache item for deferred saving.
     *
     * If the given item is not an instance of FileCacheItem, false is
     * returned. Otherwise, the item is added to the internal deferred
     * queue, and true is returned.
     *
     * @param CacheItemInterface $item
     * @return bool true if the item was successfully deferred, false otherwise
     */
    public function saveDeferred(CacheItemInterface $item): bool
    {
        if (!$item instanceof FileCacheItem) {
            return false;
        }
        $this->deferred[$item->getKey()] = $item;
        return true;
    }

    /**
     * Commit any deferred cache items.
     *
     * @return bool true if all deferred items were successfully committed.
     */
    public function commit(): bool
    {
        $ok = true;
        foreach ($this->deferred as $key => $item) {
            $ok = $ok && $this->save($item);
            unset($this->deferred[$key]);
        }
        return $ok;
    }

    /**
     * Returns the number of items in the cache.
     *
     * @return int The number of cache items.
     */
    public function count(): int
    {
        return count(glob("$this->dir*.cache"));
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
