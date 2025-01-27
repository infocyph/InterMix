<?php

namespace Infocyph\InterMix;

use Closure;
use Exception;
use Infocyph\InterMix\DI\Container;
use Infocyph\InterMix\DI\Reflection\ReflectionResource;
use Infocyph\InterMix\Exceptions\ContainerException;
use Infocyph\InterMix\Memoize\Cache;
use Infocyph\InterMix\Memoize\WeakCache;
use Psr\Cache\InvalidArgumentException;
use ReflectionException;

if (! function_exists('Infocyph\InterMix\container')) {
    /**
     * Get a Container instance or directly call a method/closure.
     *
     * If $closureOrClass is null, returns the Container (PSR-11).
     * Otherwise, we interpret it as:
     *   - A string/array describing a "class@method" or "class::method" => registerMethod, then getReturn()
     *   - A closure/callable => call it (resolve via reflection if needed)
     *   - A plain string => treat it as an ID/class => get it from container
     *
     * @return Container|mixed
     *
     * @throws ContainerException
     * @throws Exception
     */
    function container(
        string|Closure|callable|array|null $closureOrClass = null,
        string $alias = 'default'
    ): mixed {
        // 1) Retrieve the Container instance
        $instance = Container::instance($alias);

        // 2) If no class/closure is given, just return the Container
        if ($closureOrClass === null) {
            return $instance;
        }

        // 3) Use the container's InvocationManager->split(...) to parse
        //    "class@method", "class::method", [class, method], or closure/callable.
        [$class, $method] = $instance
            ->split($closureOrClass);

        // 4) If no method is extracted => possibly a closure/callable or a direct ID.
        if (! $method) {
            // If it's a closure or any callable, let's do invocationManager->call(...)
            if ($class instanceof Closure || is_callable($class)) {
                return $instance->invocation()->call($class);
            }

            // Otherwise interpret as class/ID => get($class)
            return $instance->get($class);
        }

        // 5) If we do have a method => register that method using RegistrationManager->registerMethod(...)
        $instance->registration()->registerMethod($class, $method);

        // 6) Then call getReturn($class) to actually invoke that method and return the result
        return $instance->getReturn($class);
        // or if you keep 'getReturn()' in the InvocationManager =>
        // return $instance->getInvocationManager()->getReturn($class);
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
