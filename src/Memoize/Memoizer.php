<?php

declare(strict_types=1);

namespace Infocyph\InterMix\Memoize;

use Infocyph\InterMix\DI\Reflection\ReflectionResource;
use Infocyph\InterMix\Fence\Single;
use ReflectionException;
use WeakMap;

/**
 * A singleton that provides:
 *  - global memoization (per signature)
 *  - object-scoped memoization (per-instance + signature)
 */
final class Memoizer
{
    use Single;  // your existing single-instance trait

    /** @var array<string,mixed> */
    private array $staticCache = [];

    /** @var WeakMap<object,array<string,mixed>> */
    private WeakMap $objectCache;

    private int $hits   = 0;
    private int $misses = 0;

    /** Prevent direct construction */
    protected function __construct()
    {
        $this->objectCache = new WeakMap();
    }

    /**
     * Memoize a callable for the **entire** process.
     *
     * @param callable $callable
     * @param array     $params
     * @return mixed
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

        // initialize that object's bucket
        $bucket = $this->objectCache[$object] ?? [];
        if (array_key_exists($sig, $bucket)) {
            $this->hits++;
            return $bucket[$sig];
        }

        $this->misses++;
        $v = $callable(...$params);
        $bucket[$sig] = $v;
        $this->objectCache[$object] = $bucket;
        return $v;
    }

    /** Clear **all** caches (static & object). */
    public function flush(): void
    {
        $this->staticCache = [];
        $this->objectCache = new WeakMap();
        $this->hits = $this->misses = 0;
    }

    /** Retrieve statistics. */
    public function stats(): array
    {
        return [
            'hits'   => $this->hits,
            'misses' => $this->misses,
            'total'  => $this->hits + $this->misses,
        ];
    }
}
