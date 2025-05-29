<?php

use Infocyph\InterMix\DI\Container;
use Infocyph\InterMix\DI\Reflection\ReflectionResource;
use Infocyph\InterMix\Exceptions\ContainerException;
use Infocyph\InterMix\Memoize\Cache;
use Infocyph\InterMix\Memoize\WeakCache;
use Infocyph\InterMix\Remix\TapProxy;
use Psr\Cache\InvalidArgumentException;

if (!function_exists('container')) {
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
     * @throws Exception|InvalidArgumentException
     */
    function container(
        string|Closure|callable|array|null $closureOrClass = null,
        string $alias = 'default',
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
        if (!$method) {
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

if (!function_exists('memoize')) {
    /**
     * Retrieves a memoized value of the provided callable.
     *
     * @param callable|null $callable The callable to be memoized. Defaults to null.
     * @param array $parameters The parameters to be passed to the callable. Defaults to an empty array.
     * @param int|null $ttl Time-to-live for the cached item in seconds. Defaults to null (no expiration).
     * @param bool $forceRefresh Whether to force cache refresh. Defaults to false.
     * @return mixed The memoized result of the callable or the Cache instance if no callable is provided.
     *
     * @throws ReflectionException|Exception|InvalidArgumentException
     */
    function memoize(
        ?callable $callable = null,
        array $parameters = [],
        ?int $ttl = null,
        bool $forceRefresh = false,
    ): mixed {
        $cache = Cache::instance();

        // Return the Cache instance if no callable is provided
        if ($callable === null) {
            return $cache;
        }

        // Generate and retrieve the unique signature for the callable
        $signature = ReflectionResource::getSignature(
            ReflectionResource::getReflection($callable),
        );

        // Retrieve or compute the value with optional TTL and force refresh
        return $cache->get($signature, $callable, $parameters, $ttl);
    }
}

if (!function_exists('remember')) {
    /**
     * Retrieves a memoized value based on the provided class object (valid until garbage collected).
     *
     * @param object|null $classObject The class object for which the value is being retrieved.
     * @param callable|null $callable The callable for which the value is being retrieved.
     * @param array $parameters The parameters for the callable.
     * @return mixed The memoized result of the callable or the WeakCache instance if no callable is provided.
     *
     * @throws ReflectionException
     */
    function remember(
        ?object $classObject = null,
        ?callable $callable = null,
        array $parameters = [],
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
            ReflectionResource::getReflection($callable),
        );

        // Retrieve or compute the value
        return $cache->get($classObject, $signature, $callable, $parameters);
    }
}

if (!function_exists('tap')) {
    /**
     * Invokes the given callback with the provided value and returns the value.
     * If no callback is provided, returns a TapProxy instance for the value.
     *
     * @param mixed $value The value to be passed to the callback or wrapped in a TapProxy.
     * @param callable|null $callback The callback to invoke with the value. Defaults to null.
     * @return mixed The original value or a TapProxy instance if no callback is provided.
     */
    function tap(mixed $value, ?callable $callback = null): mixed
    {
        return is_null($callback)
            ? new TapProxy($value)
            : ($callback($value) ?? $value);
    }
}

if (!function_exists('when')) {
    /**
     * Applies a callback if the given condition is truthy.
     * If no falsy callback is provided, returns the original value.
     *
     * @param mixed $value The condition value.
     * @param callable $truthy The callback to apply if the condition is truthy.
     * @param callable|null $falsy The callback to apply if the condition is falsy (optional, defaults to null).
     * @return mixed The result of the callback when executed, or the original value if the condition is falsy and no falsy callback is provided.
     */
    function when(mixed $value, callable $truthy, ?callable $falsy = null): mixed
    {
        return $value ? $truthy($value) : ($falsy ? $falsy($value) : $value);
    }
}


if (!function_exists('pipe')) {
    /**
     * Pass the value through the callback and return the callback's result.
     *
     * @param mixed $value The value to be passed to the callback.
     * @param callable $callback The callback to execute with the value.
     * @return mixed The result of the callback when executed.
     */
    function pipe(mixed $value, callable $callback): mixed
    {
        return $callback($value);
    }
}

if (!function_exists('measure')) {
    /**
     * Executes a callback function and measures its execution time in milliseconds.
     *
     * @param callable $fn The callback function to execute.
     * @param float|null &$ms A variable to store the execution time in milliseconds.
     *                         Passed by reference and will be updated with the elapsed time.
     *                         Defaults to null if not provided.
     * @return mixed The result of the callback function execution.
     */
    function measure(callable $fn, ?float &$ms = null): mixed
    {
        $t0 = microtime(true);
        $out = $fn();
        $ms = (microtime(true) - $t0) * 1000;
        return $out;
    }
}

if (!function_exists('retry')) {
    /**
     * Run the callback up to $attempts times, sleeping $delayMs (+ backoff) between
     * failures.  $shouldRetry decides whether to retry for a given Throwable.
     *
     * @param int $attempts The number of times to attempt the callback.
     * @param callable $callback The function to call, which may throw an exception.
     * @param callable|null $shouldRetry A function that takes a Throwable and
     *     returns true if the operation should be retried, false otherwise.
     * @param int $delayMs The base delay to sleep between retries, in milliseconds.
     * @param float $backoff The backoff factor to apply to the delay after each retry.
     *     Defaults to 1.0 (no backoff).  For example, a value of 2.0 will double the
     *     delay after each retry.
     *
     * @return mixed The result of the callback, if it succeeds.
     * @throws Throwable The exception that was thrown by the callback on the last
     *     attempt, if it never succeeds.
     */
    function retry(
        int $attempts,
        callable $callback,
        ?callable $shouldRetry = null,
        int $delayMs = 0,
        float $backoff = 1.0,
    ): mixed {
        $tries = 0;
        $sleep = $delayMs;

        beginning:
        try {
            return $callback(++$tries);
        } catch (Throwable $e) {
            if ($tries >= $attempts) {
                throw $e;
            }
            if ($shouldRetry && !$shouldRetry($e)) {
                throw $e;
            }
            if ($sleep > 0) {
                usleep($sleep * 1000);
            }
            $sleep = (int)($sleep * $backoff);
            goto beginning;
        }
    }
}
