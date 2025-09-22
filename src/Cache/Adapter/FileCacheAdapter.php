<?php

declare(strict_types=1);

namespace Infocyph\InterMix\Cache\Adapter;

use Countable;
use Infocyph\InterMix\Cache\Item\FileCacheItem;
use Infocyph\InterMix\Exceptions\CacheInvalidArgumentException;
use Infocyph\InterMix\Serializer\ValueSerializer;
use Psr\Cache\CacheItemInterface;
use Psr\Cache\CacheItemPoolInterface;
use RuntimeException;

class FileCacheAdapter implements CacheItemPoolInterface, Countable
{
    private array $deferred = [];
    private string $dir;

    /**
     * Creates a new instance of the FileCacheAdapter.
     *
     * @param string $namespace The namespace to use for the cache.
     * @param string|null $baseDir The base directory for the cache.
     */
    public function __construct(string $namespace = 'default', ?string $baseDir = null)
    {
        $this->createDirectory($namespace, $baseDir);
    }

    /**
     * Clears all items from the cache.
     *
     * This method deletes all cache files in the directory
     * and clears the deferred queue.
     *
     * @return bool True if all items were successfully deleted, false otherwise.
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
     * Commits all deferred cache items to the cache pool.
     *
     * This method iterates through all items that have been queued for deferred
     * saving and persists them to the cache. After saving, the items are removed
     * from the deferred queue.
     *
     * @return bool True if all deferred items were successfully saved, false otherwise.
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
     * @return int Number of cache items.
     */
    public function count(): int
    {
        return iterator_count(new \FilesystemIterator($this->dir, \FilesystemIterator::SKIP_DOTS));
    }

    /**
     * Deletes a single item from the cache.
     *
     * This method deletes the item from the cache if it exists. If the item does
     * not exist, it is silently ignored.
     *
     * @param string $key Cache key.
     * @return bool True if the item was successfully deleted, false otherwise.
     */
    public function deleteItem(string $key): bool
    {
        return @unlink($this->fileFor($key));
    }

    /**
     * Deletes multiple items from the cache.
     *
     * This method deletes all given items from the cache. If an item does not
     * exist, it is silently ignored.
     *
     * @param string[] $keys An array of keys to delete.
     *
     * @return bool True if all items were successfully deleted, false otherwise.
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
     * Fetches a value from the cache.
     *
     * @param string $key Cache key.
     * @return mixed|null Value associated with the key, or null if the key does not exist.
     */
    public function get(string $key): mixed
    {
        $item = $this->getItem($key);
        return $item->isHit() ? $item->get() : null;
    }

    /**
     * Retrieves a Cache Item for the given key.
     *
     * @param string $key Cache key.
     *
     * @return CacheItemInterface The retrieved Cache Item.
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
     * Returns an iterable of {@see CacheItemInterface} objects resulting from
     * a cache fetch of the given keys.
     *
     * The keys should be an array of strings, where each string is a cache key
     * to fetch. The return value will be an iterable of {@see CacheItemInterface}
     * objects, each keyed by the cache key of the respective item.
     *
     * @param string[] $keys An array of keys to fetch from the cache.
     *
     * @return iterable An iterable of {@see CacheItemInterface} objects
     *                  resulting from the cache fetch.
     */
    public function getItems(array $keys = []): iterable
    {
        foreach ($keys as $k) {
            yield $k => $this->getItem($k);
        }
    }

    /**
     * Confirms if the cache contains specified cache item.
     *
     * Note: This method may use a cached value to respond until the cache item
     * is deleted.
     *
     * @param string $key Cache item key.
     *
     * @return bool True if the specified cache item exists in the cache,
     *              false otherwise.
     */
    public function hasItem(string $key): bool
    {
        return $this->getItem($key)->isHit();
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

    /**
     * Persists a cache item immediately.
     *
     * This method is a no-op if the item is not an instance of FileCacheItem.
     *
     * @param CacheItemInterface $item The cache item to persist.
     * @return bool True if the item was successfully persisted, false otherwise.
     * @throws CacheInvalidArgumentException if the item is not a FileCacheItem.
     */
    public function save(CacheItemInterface $item): bool
    {
        if (!$item instanceof FileCacheItem) {
            throw new CacheInvalidArgumentException('Invalid item type for FileCacheAdapter');
        }
        $blob = ValueSerializer::serialize($item);
        $tmp = tempnam($this->dir, 'c_');
        return file_put_contents($tmp, $blob) !== false && rename($tmp, $this->fileFor($item->getKey()));
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
    public function saveDeferred(CacheItemInterface $item): bool
    {
        if (!$item instanceof FileCacheItem) {
            return false;
        }
        $this->deferred[$item->getKey()] = $item;
        return true;
    }


    /**
     * PSR-16: adds a value to the cache, optionally with a TTL.
     *
     * @param string $key
     * @param mixed  $value
     * @param int|null $ttl   Time-to-live in seconds or null for no TTL.
     * @return bool
     */
    public function set(string $key, mixed $value, ?int $ttl = null): bool
    {
        $item = $this->getItem($key);
        $item->set($value)->expiresAfter($ttl);
        return $this->save($item);
    }

    /**
     * Sets the namespace and base directory for the cache.
     *
     * This method updates the cache directory based on the provided namespace and base directory.
     * It creates the directory if it does not exist and clears the deferred queue.
     *
     * @param string $namespace The namespace to use for the directory name.
     * @param string|null $baseDir The base directory where the cache directory will be created.
     */
    public function setNamespaceAndDirectory(string $namespace, ?string $baseDir = null): void
    {
        $this->createDirectory($namespace, $baseDir);
        $this->deferred = [];
    }

    /**
     * Creates a cache directory based on the given namespace and base path.
     *
     * This method constructs a directory path using the provided namespace and base directory.
     * It ensures that the directory is created and is writable. If the directory path already
     * exists but is not a directory, or if the directory cannot be created or is not writable,
     * an exception is thrown.
     *
     * @param string $ns   The namespace to use for the directory name, sanitized to allow only
     *                     alphanumeric characters, underscores, and hyphens.
     * @param string|null $baseDir The base directory where the cache directory will be created.
     *                          Defaults to the system's temporary directory if null.
     *
     * @throws RuntimeException If the directory cannot be created or is not writable, or if a
     *                          file with the same name already exists.
     */
    private function createDirectory(string $ns, ?string $baseDir): void
    {
        $baseDir = rtrim($baseDir ?? sys_get_temp_dir(), DIRECTORY_SEPARATOR);
        $ns = sanitize_cache_ns($ns);
        $this->dir = $baseDir . DIRECTORY_SEPARATOR . 'cache_' . $ns . DIRECTORY_SEPARATOR;

        if (is_dir($this->dir)) {
            if (!is_writable($this->dir)) {
                throw new RuntimeException(
                    "Cache directory '$this->dir' exists but is not writable"
                );
            }
            return;
        }

        if (file_exists($baseDir) && !is_dir($baseDir)) {
            throw new RuntimeException(
                'Cache base path ' . realpath($baseDir) . ' exists and is *not* a directory'
            );
        }

        if (!is_dir($baseDir) && !@mkdir($baseDir, 0770, true) && !is_dir($baseDir)) {
            $err = error_get_last()['message'] ?? 'unknown error';
            throw new RuntimeException(
                'Failed to create base directory ' . $baseDir . ": $err"
            );
        }

        if (file_exists($this->dir) && !is_dir($this->dir)) {
            throw new RuntimeException(
                realpath($this->dir) . ' exists and is not a directory'
            );
        }

        if (!@mkdir($this->dir, 0770, true) && !is_dir($this->dir)) {
            $err = error_get_last()['message'] ?? 'unknown error';
            throw new RuntimeException(
                'Failed to create cache directory ' . $this->dir . ": $err"
            );
        }

        if (!is_writable($this->dir)) {
            throw new RuntimeException(
                'Cache directory ' . $this->dir . ' is not writable'
            );
        }
    }

    /**
     * Converts a cache key to a file name.
     *
     * @param string $key Cache key.
     *
     * @return string The file name for the cache item.
     */
    private function fileFor(string $key): string
    {
        return $this->dir . hash('xxh128', $key) . '.cache';
    }
}
