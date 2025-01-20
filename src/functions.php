<?php

namespace Infocyph\InterMix;

use Closure;
use Exception;
use Infocyph\InterMix\DI\Container;
use Infocyph\InterMix\DI\Reflection\ReflectionResource;
use Infocyph\InterMix\Exceptions\ContainerException;
use Infocyph\InterMix\Exceptions\NotFoundException;
use Infocyph\InterMix\Memoize\Cache;
use Infocyph\InterMix\Memoize\WeakCache;
use Psr\Cache\InvalidArgumentException;
use ReflectionException;

if (! function_exists('Infocyph\InterMix\container')) {
    /**
     * Get Container instance or direct call method/closure
     *
     * @param  string|Closure|callable|array|null  $closureOrClass  The closure, class, or callable array.
     * @param  string  $alias  The alias for the container instance.
     * @return Container|mixed Container or The return value of the function.
     *
     * @throws ContainerException|NotFoundException|InvalidArgumentException
     */
    function container(string|Closure|callable|array|null $closureOrClass = null, string $alias = 'default')
    {
        $instance = Container::instance($alias);
        if ($closureOrClass === null) {
            return $instance;
        }

        [$class, $method] = $instance->split($closureOrClass);
        if (! $method) {
            return $instance->get($class);
        }

        $instance->registerMethod($class, $method);

        return $instance->getReturn($class);
    }
}

if (! function_exists('Infocyph\InterMix\memoize')) {
    /**
     * Retrieves a memoized value of the provided callable.
     *
     * @param  callable|null  $callable  The callable to be memoized. Defaults to null.
     * @param  array  $parameters  The parameters to be passed to the callable. Defaults to an empty array.
     * @param  int|null  $ttl  Time-to-live for the cached item in seconds. Defaults to null (no expiration).
     * @param  bool  $forceRefresh  Whether to force cache refresh. Defaults to false.
     * @return mixed The memoized result of the callable or the Cache instance if no callable is provided.
     *
     * @throws ReflectionException|Exception|InvalidArgumentException
     */
    function memoize(?callable $callable = null, array $parameters = [], ?int $ttl = null, bool $forceRefresh = false): mixed
    {
        $cache = Cache::instance();

        // Return the Cache instance if no callable is provided
        if ($callable === null) {
            return $cache;
        }

        // Generate and retrieve the unique signature for the callable
        $signature = ReflectionResource::getSignature(
            ReflectionResource::resolveCallable($callable)
        );

        // Retrieve or compute the value with optional TTL and force refresh
        return $cache->get($signature, $callable, $parameters, $ttl, $forceRefresh);
    }
}

if (! function_exists('Infocyph\InterMix\remember')) {
    /**
     * Retrieves a memoized value based on the provided class object (valid until garbage collected).
     *
     * @param  object|null  $classObject  The class object for which the value is being retrieved.
     * @param  callable|null  $callable  The callable for which the value is being retrieved.
     * @param  array  $parameters  The parameters for the callable.
     * @param  array  $tags  Tags to associate with the cache entry.
     * @return mixed The memoized result of the callable or the WeakCache instance if no callable is provided.
     *
     * @throws ReflectionException|InvalidArgumentException
     */
    function remember(
        ?object $classObject = null,
        ?callable $callable = null,
        array $parameters = [],
        array $tags = []
    ): mixed {
        $cache = WeakCache::instance();

        // Return the cache instance if no class object is provided
        if ($classObject === null) {
            return $cache;
        }

        // Validate callable
        if ($callable === null) {
            throw new \InvalidArgumentException('A callable must be provided to remember a value.');
        }

        // Generate the unique signature
        $signature = ReflectionResource::getSignature(
            ReflectionResource::resolveCallable($callable)
        );

        // Retrieve or compute the value
        return $cache->get($classObject, $signature, $callable, $parameters);
    }
}
