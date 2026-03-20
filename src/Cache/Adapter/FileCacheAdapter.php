<?php

declare(strict_types=1);

namespace Infocyph\InterMix\Cache\Adapter;

use Infocyph\InterMix\Cache\Item\FileCacheItem;
use Infocyph\InterMix\Exceptions\CacheInvalidArgumentException;
use Psr\Cache\CacheItemInterface;
use RuntimeException;

/**
 * File-based cache adapter implementation.
 *
 * This adapter stores cache data as individual files in a specified directory.
 * Each cache entry is serialized and stored with a .cache extension.
 * It provides a simple filesystem-based caching solution suitable for
 * development environments or applications without access to dedicated cache systems.
 */
class FileCacheAdapter extends AbstractCacheAdapter
{
    private string $dir;

    /**
     * Creates a new file-based cache adapter.
     *
     * @param string $namespace A namespace prefix for cache files to avoid collisions.
     * @param string|null $baseDir The base directory for cache files. If null, uses system temp directory.
     * @throws RuntimeException If the cache directory cannot be created or is not writable.
     */
    public function __construct(string $namespace = 'default', ?string $baseDir = null)
    {
        $this->createDirectory($namespace, $baseDir);
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

    public function count(): int
    {
        return iterator_count(new \FilesystemIterator($this->dir, \FilesystemIterator::SKIP_DOTS));
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

    public function getItem(string $key): FileCacheItem
    {
        $file = $this->fileFor($key);

        if (is_file($file)) {
            $raw = file_get_contents($file);
            if (is_string($raw)) {
                $record = CachePayloadCodec::decode($raw);
                if ($record !== null && !CachePayloadCodec::isExpired($record['expires'])) {
                    return new FileCacheItem(
                        $this,
                        $key,
                        $record['value'],
                        true,
                        CachePayloadCodec::toDateTime($record['expires']),
                    );
                }
            }
            @unlink($file);
        }

        return new FileCacheItem($this, $key);
    }

    public function hasItem(string $key): bool
    {
        return $this->getItem($key)->isHit();
    }

    public function save(CacheItemInterface $item): bool
    {
        if (!$this->supportsItem($item)) {
            throw new CacheInvalidArgumentException('Invalid item type for FileCacheAdapter');
        }

        $expires = CachePayloadCodec::expirationFromItem($item);
        $ttl = $expires['ttl'];
        if ($ttl === 0) {
            return $this->deleteItem($item->getKey());
        }

        $blob = CachePayloadCodec::encode($item->get(), $expires['expiresAt']);
        $tmp = tempnam($this->dir, 'c_');
        return file_put_contents($tmp, $blob) !== false && rename($tmp, $this->fileFor($item->getKey()));
    }

    public function setNamespaceAndDirectory(string $namespace, ?string $baseDir = null): void
    {
        $this->createDirectory($namespace, $baseDir);
        $this->deferred = [];
    }

    protected function supportsItem(CacheItemInterface $item): bool
    {
        return $item instanceof FileCacheItem;
    }

    private function assertWritableDirectory(string $path, string $message): void
    {
        if (!is_writable($path)) {
            throw new RuntimeException($message);
        }
    }

    private function createDirectory(string $ns, ?string $baseDir): void
    {
        $baseDir = rtrim($baseDir ?? sys_get_temp_dir(), DIRECTORY_SEPARATOR);
        $ns = sanitize_cache_ns($ns);
        $this->dir = $baseDir . DIRECTORY_SEPARATOR . 'cache_' . $ns . DIRECTORY_SEPARATOR;

        if (is_dir($this->dir)) {
            $this->assertWritableDirectory($this->dir, "Cache directory '$this->dir' exists but is not writable");
            return;
        }

        $this->ensureBaseDirectoryExists($baseDir);
        $this->ensureCacheDirectoryExists($this->dir);
        $this->assertWritableDirectory($this->dir, 'Cache directory ' . $this->dir . ' is not writable');
    }

    private function ensureBaseDirectoryExists(string $baseDir): void
    {
        if (file_exists($baseDir) && !is_dir($baseDir)) {
            throw new RuntimeException(
                'Cache base path ' . realpath($baseDir) . ' exists and is *not* a directory'
            );
        }

        if (!is_dir($baseDir) && !@mkdir($baseDir, 0770, true) && !is_dir($baseDir)) {
            $this->throwCreationError('Failed to create base directory ' . $baseDir);
        }
    }

    private function ensureCacheDirectoryExists(string $cacheDir): void
    {
        if (file_exists($cacheDir) && !is_dir($cacheDir)) {
            throw new RuntimeException(
                realpath($cacheDir) . ' exists and is not a directory'
            );
        }

        if (!@mkdir($cacheDir, 0770, true) && !is_dir($cacheDir)) {
            $this->throwCreationError('Failed to create cache directory ' . $cacheDir);
        }
    }

    private function fileFor(string $key): string
    {
        return $this->dir . hash('xxh128', $key) . '.cache';
    }

    private function throwCreationError(string $prefix): void
    {
        $err = error_get_last()['message'] ?? 'unknown error';
        throw new RuntimeException($prefix . ": $err");
    }
}
