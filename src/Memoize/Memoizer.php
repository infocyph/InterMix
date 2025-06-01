<?php

declare(strict_types=1);

namespace Infocyph\InterMix\Memoize;

use Infocyph\InterMix\DI\Reflection\ReflectionResource;
use Infocyph\InterMix\Fence\Single;
use ReflectionException;
use WeakMap;

final class Memoizer
{
    use Single;

    /** @var array<string,mixed> */
    private array $staticCache = [];

    /** @var WeakMap<object,array<string,mixed>> */
    private WeakMap $objectCache;

    private int $hits   = 0;
    private int $misses = 0;


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

        if (array_key_exists($sig, $this->staticCache)) {
            $this->hits++;
            return $this->staticCache[$sig];
        }

        $this->misses++;
        $v = $callable(...$params);
        $this->staticCache[$sig] = $v;
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

        $bucket = $this->objectCache[$object] ?? [];
        if (array_key_exists($sig, $bucket)) {
            $this->hits++;
            return $bucket[$sig];
        }

        $this->misses++;
        $value = $callable(...$params);
        $bucket[$sig] = $value;
        $this->objectCache[$object] = $bucket;
        return $value;
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
}
