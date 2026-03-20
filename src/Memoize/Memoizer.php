<?php

declare(strict_types=1);

namespace Infocyph\InterMix\Memoize;

use Infocyph\InterMix\DI\Support\ReflectionResource;
use Infocyph\InterMix\Fence\Single;
use ReflectionException;
use WeakMap;

final class Memoizer
{
    use Single;

    private int $hits   = 0;
    private int $misses = 0;

    /** @var WeakMap<object,array<string,mixed>> */
    private WeakMap $objectCache;

    /** @var array<string,mixed> */
    private array $staticCache = [];


    /**
     * Creates a new Memoizer instance.
     *
     * This constructor initializes an empty WeakMap for object-scoped memoization.
     */
    protected function __construct()
    {
        $this->objectCache = new WeakMap();
    }

    /**
     * Clears all cached entries and resets statistics.
     *
     * This method empties both the static and object-specific caches,
     * and resets the hit and miss counters to zero.
     */
    public function flush(): void
    {
        $this->staticCache = [];
        $this->objectCache = new WeakMap();
        $this->hits = $this->misses = 0;
    }

    /**
     * Memoize a callable for the **entire** process.
     *
     * @param callable $callable
     * @param array $params
     * @return mixed
     * @throws ReflectionException
     */
    public function get(callable $callable, array $params = []): mixed
    {
        $sig = ReflectionResource::getSignature(
            ReflectionResource::getReflection($callable)
        );
        $cacheKey = self::buildCacheKey($sig, $params);

        if (array_key_exists($cacheKey, $this->staticCache)) {
            $this->hits++;
            return $this->staticCache[$cacheKey];
        }

        $this->misses++;
        $v = $callable(...$params);
        $this->staticCache[$cacheKey] = $v;
        return $v;
    }

    /**
     * Memoize a callable **per object instance**.
     *
     * @param object $object
     * @param callable $callable
     * @param array $params
     * @return mixed
     * @throws ReflectionException
     */
    public function getFor(object $object, callable $callable, array $params = []): mixed
    {
        $sig = ReflectionResource::getSignature(
            ReflectionResource::getReflection($callable)
        );
        $cacheKey = self::buildCacheKey($sig, $params);

        $bucket = $this->objectCache[$object] ?? [];
        if (array_key_exists($cacheKey, $bucket)) {
            $this->hits++;
            return $bucket[$cacheKey];
        }

        $this->misses++;
        $value = $callable(...$params);
        $bucket[$cacheKey] = $value;
        $this->objectCache[$object] = $bucket;
        return $value;
    }


    /**
     * Retrieve memoization statistics.
     *
     * @return array An associative array containing:
     *               - 'hits': The number of cache hits.
     *               - 'misses': The number of cache misses.
     *               - 'total': The total number of cache accesses (hits + misses).
     */
    public function stats(): array
    {
        return [
            'hits'   => $this->hits,
            'misses' => $this->misses,
            'total'  => $this->hits + $this->misses,
        ];
    }

    /**
     * Build a stable memoization cache key from callable signature + argument values.
     */
    private static function buildCacheKey(string $sig, array $params): string
    {
        if ($params === []) {
            return $sig;
        }

        $normalized = array_map(self::normalizeParam(...), $params);
        return $sig . '|' . hash('xxh3', serialize($normalized));
    }

    /**
     * Normalize runtime values into serializable scalar/array forms for cache keys.
     */
    private static function normalizeParam(mixed $value): mixed
    {
        return match (true) {
            $value instanceof \Closure => 'closure#' . spl_object_id($value),
            is_object($value) => 'obj#' . spl_object_id($value),
            is_resource($value) => 'res#' . get_resource_type($value) . '#' . (int)$value,
            is_array($value) => array_map(self::normalizeParam(...), $value),
            default => $value,
        };
    }
}
