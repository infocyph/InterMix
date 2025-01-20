<?php

namespace Infocyph\InterMix\Memoize;

use Infocyph\InterMix\Fence\Single;
use Psr\Cache\InvalidArgumentException;
use RuntimeException;
use Symfony\Component\Cache\Adapter\FilesystemAdapter;
use Symfony\Contracts\Cache\CacheInterface;

class Cache
{
    use Single;

    private CacheInterface $cacheAdapter;

    private string $namespace = '';

    public function __construct()
    {
        $this->cacheAdapter = new FilesystemAdapter('', 0, sys_get_temp_dir());
    }

    /**
     * Set a custom cache driver.
     *
     * @param  CacheInterface  $cache  The custom cache adapter implementing CacheInterface.
     */
    public function setCacheDriver(CacheInterface $cache): self
    {
        $this->cacheAdapter = $cache;

        return $this;
    }

    /**
     * Set a namespace for cache keys.
     *
     * @param  string  $namespace  The namespace to prepend to all cache keys.
     */
    public function setNamespace(string $namespace): self
    {
        $this->namespace = rtrim($namespace, ':').':';

        return $this;
    }

    /**
     * Retrieves a value from the cache if it exists, otherwise calls a given function
     * to generate the value and stores it in the cache for future use.
     *
     * @param  string  $signature  The unique signature of the value to retrieve.
     * @param  callable  $callable  The function to call if the value does not exist in the cache.
     * @param  array  $parameters  The parameters to pass to the callable function.
     * @param  int|null  $ttl  Time-to-live for the cached item in seconds (optional).
     * @param  bool  $forceRefresh  Whether to force refreshing the cache.
     * @return mixed The retrieved value from the cache or the generated value from the callable function.
     *
     * @throws InvalidArgumentException
     */
    public function get(string $signature, callable $callable, array $parameters = [], ?int $ttl = null, bool $forceRefresh = false): mixed
    {
        $signature = $this->resolveSignature($signature);

        if (! $forceRefresh && $this->cacheAdapter->hasItem($signature)) {
            return $this->cacheAdapter->getItem($signature)->get();
        }

        $value = call_user_func_array($callable, $parameters);

        $item = $this->cacheAdapter->getItem($signature);
        $item->set($value);

        if ($ttl !== null) {
            $item->expiresAfter($ttl);
        }

        $this->cacheAdapter->save($item);

        return $value;
    }

    /**
     * Forget cache by key.
     *
     * @param  string  $signature  The unique signature of the value to forget.
     *
     */
    public function forget(string $signature): void
    {
        try {
            $this->cacheAdapter->deleteItem($this->resolveSignature($signature));
        } catch (InvalidArgumentException $e) {
            throw new RuntimeException("Failed to delete cache item: $signature", 0, $e);
        }
    }

    /**
     * Flush the cache.
     */
    public function flush(): void
    {
        $this->cacheAdapter->clear();
    }

    /**
     * Check if a cache item exists.
     *
     * @param  string  $signature  The unique signature of the cache item.
     */
    public function has(string $signature): bool
    {
        try {
            return $this->cacheAdapter->hasItem($this->resolveSignature($signature));
        } catch (InvalidArgumentException) {
            return false;
        }
    }

    /**
     * Get all cache keys (if supported by the adapter).
     */
    public function getKeys(): array
    {
        if (method_exists($this->cacheAdapter, 'getAdapter')) {
            $adapter = $this->cacheAdapter->getAdapter();
            if ($adapter instanceof FilesystemAdapter) {
                $directory = sys_get_temp_dir();

                return array_filter(
                    array_map(fn ($file) => basename($file), scandir($directory) ?: []),
                    fn ($file) => str_starts_with($file, $this->namespace)
                );
            }
        }

        return []; // Fallback for unsupported adapters
    }

    /**
     * Resolve the cache key with the namespace.
     */
    private function resolveSignature(string $signature): string
    {
        return $this->namespace.$signature;
    }
}
