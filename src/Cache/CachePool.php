<?php

namespace Infocyph\InterMix\Cache;

use ArrayAccess;
use Countable;
use Infocyph\InterMix\Cache\Adapter\FileCacheAdapter;
use Infocyph\InterMix\Exceptions\CacheInvalidArgumentException;
use Iterator;
use Psr\Cache\CacheItemInterface;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Cache\InvalidArgumentException;
use RuntimeException;

class CachePool implements
    CacheItemPoolInterface,
    ArrayAccess,
    Countable,
    Iterator
{
    private string $directory;
    private array $deferred = [];
    private array $keys = [];
    private int $position = 0;

    /**
     * Initializes the cache pool.
     *
     * @param string $namespace The namespace for the cache, used in the directory path.
     * @param string|null $directory The base directory for the cache. If null, the system temp
     *                               directory is used.
     *
     * @throws RuntimeException If the cache directory cannot be created.
     */
    public function __construct(string $namespace = 'intermix', ?string $directory = null)
    {
        $this->createDirectory($namespace, $directory);
    }

    /**
     * Retrieves a Cache Item for the given key.
     *
     * If the item does not exist, a new one is created with the given key and
     * a value of null. If the item exists, its value is returned unless the
     * item is expired, in which case a new one is created with the given key
     * and a value of null.
     *
     * @param string $key The key of the cache item to retrieve.
     *
     * @return CacheItemInterface The retrieved cache item.
     *
     * @throws InvalidArgumentException If the key is invalid.
     */
    public function getItem(string $key): CacheItemInterface
    {
        $this->validateKey($key);
        $file = $this->getFilePath($key);

        if (is_file($file)) {
            $item = unserialize(
                (string)file_get_contents($file),
                ['allowed_classes' => [FileCacheAdapter::class]],
            );
            if ($item instanceof FileCacheAdapter && $item->isHit()) {
                return $item;
            }
            @unlink($file);
        }

        return new FileCacheAdapter($this, $key, null, false, null);
    }

    /**
     * Returns an iterable of cache items.
     *
     * This method retrieves the cache items associated with the specified keys
     * and returns them as an iterable. The iterable contains the requested keys
     * and their associated cache items. If a given key is not found, it is
     * omitted from the iterable.
     *
     * @param array $keys The keys of the cache items to retrieve.
     *
     * @return iterable An iterable of cache items.
     * @throws InvalidArgumentException
     */
    public function getItems(array $keys = []): iterable
    {
        foreach ($keys as $key) {
            yield $key => $this->getItem($key);
        }
    }

    /**
     * Checks if a cache item with the given key exists and is a hit.
     *
     * This method retrieves the cache item associated with the specified key
     * and determines if it is a hit. A cache item is considered a hit if it
     * exists and is not expired.
     *
     * @param string $key The key of the cache item to check.
     *
     * @return bool True if the cache item is a hit, false otherwise.
     * @throws InvalidArgumentException
     */
    public function hasItem(string $key): bool
    {
        return $this->getItem($key)->isHit();
    }

    /**
     * Clears the cache pool.
     *
     * This method attempts to remove all cache files within the cache directory.
     * If all files are successfully deleted, the method returns true. Otherwise,
     * it returns false.
     *
     * @return bool True if all files were successfully deleted, false otherwise.
     */
    public function clear(): bool
    {
        $ok = true;
        foreach (glob($this->directory . '*.cache') as $file) {
            $ok = $ok && @unlink($file);
        }
        $this->deferred = [];
        return $ok;
    }

    /**
     * Deletes a cache item by its key.
     *
     * This method attempts to remove the cache file associated with the given key.
     * If the file does not exist, the method returns true. If the file exists and
     * is successfully deleted, the method also returns true. Otherwise, it returns false.
     *
     * @param string $key The key of the cache item to delete.
     *
     * @return bool True if the item was successfully deleted or did not exist, false otherwise.
     * @throws CacheInvalidArgumentException If the key is invalid.
     */
    public function deleteItem(string $key): bool
    {
        $this->validateKey($key);
        $file = $this->getFilePath($key);
        return !is_file($file) || @unlink($file);
    }

    /**
     * Deletes multiple cache items.
     *
     * @param array $keys The keys of the cache items to delete.
     *
     * @return bool True if all items were successfully deleted, false otherwise.
     * @throws InvalidArgumentException
     */
    public function deleteItems(array $keys): bool
    {
        $ok = true;
        foreach ($keys as $key) {
            $ok = $ok && $this->deleteItem($key);
        }
        return $ok;
    }

    /**
     * Saves the cache item.
     *
     * Only items of type {@see FileCacheAdapter} can be saved.
     *
     * @param CacheItemInterface $item The cache item to save.
     *
     * @return bool True if the item was successfully saved, false otherwise.
     *
     * @throws CacheInvalidArgumentException
     */
    public function save(CacheItemInterface $item): bool
    {
        if (!$item instanceof FileCacheAdapter) {
            throw new CacheInvalidArgumentException('Can only save FileCacheItem instances');
        }
        $this->validateKey($item->getKey());
        $file = $this->getFilePath($item->getKey());
        return (bool)file_put_contents($file, serialize($item), LOCK_EX);
    }

    /**
     * Adds a cache item to the list of deferred items.
     *
     * Only items of type {@see FileCacheAdapter} can be deferred.
     *
     * @param CacheItemInterface $item The cache item to defer.
     *
     * @return bool True if the item was successfully deferred, false otherwise.
     *
     * @throws CacheInvalidArgumentException
     */
    public function saveDeferred(CacheItemInterface $item): bool
    {
        if (!$item instanceof FileCacheAdapter) {
            throw new CacheInvalidArgumentException('Can only defer FileCacheItem instances');
        }
        $this->deferred[$item->getKey()] = $item;
        return true;
    }

    /**
     * Persists any deferred cache items.
     *
     * @return bool True if all deferred items were successfully saved, false otherwise.
     */
    public function commit(): bool
    {
        $ok = true;
        foreach ($this->deferred as $item) {
            $ok = $ok && $this->save($item);
        }
        $this->deferred = [];
        return $ok;
    }


    /**
     * Sets a value in the cache.
     *
     * @param string $key The key of the value to set.
     * @param mixed $value The value to set.
     * @param int|null $ttl The time to live for the value in seconds, or null for no expiration.
     *
     * @return bool True if the value was saved, false otherwise.
     * @throws InvalidArgumentException
     */
    public function set(string $key, mixed $value, ?int $ttl = null): bool
    {
        $item = $this
            ->getItem($key)
            ->set($value)
            ->expiresAfter($ttl);
        return $this->save($item);
    }


    /**
     * Gets a cache item.
     *
     * This method is part of the CacheItemPoolInterface.
     *
     * @param string $key The key of the cache item to retrieve.
     *
     * @return mixed The value stored in the cache item, or null if the key does not exist.
     * @throws InvalidArgumentException
     */
    public function get(string $key): mixed
    {
        $item = $this->getItem($key);
        return $item->isHit() ? $item->get() : null;
    }

    /**
     * Set the namespace and optionally the directory for this cache pool.
     *
     * This changes the directory where cache items are stored, and the namespace
     * used to form the directory path.
     *
     * @param string $namespace The namespace for the cache, used in the directory path.
     * @param string|null $directory The base directory for the cache. If null, the system temp
     *                               directory is used.
     *
     * @return void
     */
    public function setNamespaceAndDirectory(string $namespace, ?string $directory = null): void
    {
        $this->createDirectory($namespace, $directory);
    }

    /**
     * Checks if a cache item exists.
     *
     * This method is part of the ArrayAccess interface. It is used to determine
     * whether a cache item with the given key exists in the cache.
     *
     * @param mixed $offset The key of the cache item to check.
     *
     * @return bool True if the cache item exists, false otherwise.
     * @throws InvalidArgumentException
     */
    public function offsetExists(mixed $offset): bool
    {
        return $this->hasItem((string)$offset);
    }

    /**
     * Gets a cache item.
     *
     * This method is part of the ArrayAccess interface. It is used to retrieve
     * a cache item from the cache. If the key does not exist, null is returned.
     *
     * @param mixed $offset The key of the cache item to retrieve.
     *
     * @return mixed The value stored in the cache item, or null if the key does not exist.
     * @throws InvalidArgumentException
     */
    public function offsetGet(mixed $offset): mixed
    {
        return $this->get((string)$offset);
    }

    /**
     * Sets a cache item.
     *
     * This method is part of the ArrayAccess interface. It is used to store a
     * cache item in the cache. If the key already exists, the value is
     * overwritten.
     *
     * @param mixed $offset The key of the cache item to set.
     * @param mixed $value The value to store in the cache item.
     *
     * @return void
     * @throws InvalidArgumentException
     */
    public function offsetSet(mixed $offset, mixed $value): void
    {
        $this->set((string)$offset, $value);
    }

    /**
     * Deletes a cache item.
     *
     * This method is part of the ArrayAccess interface. It is used to remove a
     * cache item from the cache. If the key does not exist, nothing is done.
     *
     * @param mixed $offset The key of the cache item to delete.
     *
     * @return void
     * @throws InvalidArgumentException
     */
    public function offsetUnset(mixed $offset): void
    {
        $this->deleteItem((string)$offset);
    }

    /**
     * Counts the number of cache items stored in the directory.
     *
     * This method is part of the Countable interface. It returns the total
     * number of cache files currently stored in the cache directory.
     *
     * @return int The number of cache items.
     */
    public function count(): int
    {
        return count(glob($this->directory . '*.cache'));
    }

    /**
     * Rewinds the iterator to the beginning of the list.
     *
     * This method is part of the Iterator interface. It is used to reset the iterator
     * so that it points to the first element of the list.
     *
     * @return void
     */
    public function rewind(): void
    {
        $files = glob($this->directory . '*.cache');
        $this->keys = [];

        foreach ($files as $file) {
            $item = unserialize(
                (string) file_get_contents($file),
                ['allowed_classes' => [FileCacheAdapter::class]]
            );

            if ($item instanceof FileCacheAdapter && $item->isHit()) {
                $this->keys[] = $item->getKey();
            }
        }

        $this->position = 0;
    }

    /**
     * Returns the value of the current item.
     *
     * @return mixed The value of the current item.
     * @throws InvalidArgumentException
     */
    public function current(): mixed
    {
        return $this->get($this->keys[$this->position]);
    }

    /**
     * {@inheritDoc}
     *
     * @return string The cache key.
     */
    public function key(): mixed
    {
        return $this->keys[$this->position];
    }

    /**
     * Advances the iterator to the next item.
     *
     * @return void
     */
    public function next(): void
    {
        $this->position++;
    }

    /**
     * {@inheritDoc}
     *
     * @return bool True if the iterator is not at the end of the list, false otherwise.
     */
    public function valid(): bool
    {
        return isset($this->keys[$this->position]);
    }

    /**
     * Magic getter: get a value from the cache.
     *
     * This magic method is invoked when a property is accessed on the object.
     *
     * @param string $name The key of the value to retrieve.
     *
     * @return mixed The value, or null if the key does not exist.
     * @throws InvalidArgumentException
     */
    public function __get(string $name): mixed
    {
        return $this->get($name);
    }

    /**
     * Sets a value in the cache.
     *
     * This magic method is invoked when a property is assigned to the object.
     *
     * @param string $name The key of the value to set.
     * @param mixed $value The value to set.
     *
     * @return void
     * @throws InvalidArgumentException
     */
    public function __set(string $name, mixed $value): void
    {
        $this->set($name, $value);
    }

    /**
     * Checks if a value exists in the cache.
     *
     * This magic method is invoked when `isset()` or `empty()` is used on the object.
     *
     * @param string $name The key of the value to check.
     *
     * @return bool True if a value exists, false otherwise.
     * @throws InvalidArgumentException
     */
    public function __isset(string $name): bool
    {
        return $this->hasItem($name);
    }

    /**
     * Removes a value from the cache.
     *
     * This magic method is invoked when `unset()` is used on the object.
     *
     * @param string $name The key of the value to remove.
     * @throws InvalidArgumentException
     */
    public function __unset(string $name): void
    {
        $this->deleteItem($name);
    }

    /**
     * Handles dynamic method calls on the object.
     *
     * This magic method allows for the invocation of non-existent methods
     * by attempting to call them on the current object. If the method exists,
     * it will be called with the provided arguments. If no arguments are provided,
     * it attempts to retrieve a value associated with the method name as a key.
     * If a single argument is provided, it attempts to set a value for the given
     * method name as a key.
     *
     * @param string $name The name of the method being called.
     * @param array $arguments An array of arguments passed to the method.
     *
     * @return mixed The result of the method call, the retrieved value, or the
     *               result of setting a value.
     *
     * @throws CacheInvalidArgumentException|InvalidArgumentException If the method does not exist on the object.
     */
    public function __call(string $name, array $arguments): mixed
    {
        if (method_exists($this, $name)) {
            return $this->$name(...$arguments);
        }
        if (count($arguments) === 0) {
            return $this->get($name);
        }
        if (count($arguments) === 1) {
            return $this->set($name, $arguments[0]);
        }
        throw new CacheInvalidArgumentException("Undefined method {$name}()");
    }

    /**
     * Compute the file path for a given cache key.
     *
     * @param string $key The cache key.
     *
     * @return string The file path.
     */
    private function getFilePath(string $key): string
    {
        return $this->directory
            . hash('xxh128', $key)
            . '.cache';
    }

    /**
     * Validate a cache key.
     *
     * @param string $key The key to validate.
     *
     * @throws CacheInvalidArgumentException If the key is invalid.
     */
    private function validateKey(string $key): void
    {
        if ($key === '' || !preg_match('/^[A-Za-z0-9_.\-]+$/', $key)) {
            throw new CacheInvalidArgumentException(
                'Invalid cache key; allowed characters: A–Z, a–z, 0–9, _, ., -',
            );
        }
    }

    /**
     * Creates a cache directory based on the given namespace and directory.
     *
     * The namespace is sanitized to allow only alphanumeric characters, underscores, and hyphens,
     * replacing any other characters with underscores. The directory is set to a default system
     * temp directory if not provided, and appended with the sanitized namespace to form the full
     * cache path.
     *
     * @param string $namespace The namespace for the cache, used in the directory path.
     * @param string|null $directory The base directory for the cache. If null, the system temp
     *                               directory is used.
     *
     * @throws RuntimeException If the cache directory cannot be created.
     */
    private function createDirectory(string $namespace, ?string $directory = null): void
    {
        $namespace = preg_replace('/[^A-Za-z0-9_\-]/', '_', $namespace);
        $this->directory = rtrim($directory ?? sys_get_temp_dir(), DIRECTORY_SEPARATOR)
            . DIRECTORY_SEPARATOR
            . 'cache_' . $namespace
            . DIRECTORY_SEPARATOR;

        // 1) If something exists at the path but isn't a directory
        if (file_exists($this->directory) && !is_dir($this->directory)) {
            throw new RuntimeException(
                "Cache path '$this->directory' exists and is not a directory",
            );
        }

        // 2) Try to create it
        if (!is_dir($this->directory)) {
            if (!mkdir($this->directory, 0770, true)) {
                $err = error_get_last();
                $msg = $err['message'] ?? 'unknown error';
                throw new RuntimeException(
                    "Failed to create cache directory '$this->directory': {$msg}",
                );
            }
        }

        // 3) Ensure it’s actually writable
        if (!is_writable($this->directory)) {
            throw new RuntimeException(
                "Cache directory '$this->directory' is not writable",
            );
        }
    }
}
