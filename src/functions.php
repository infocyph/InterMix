<?php

use Infocyph\InterMix\DI\Container;
use Infocyph\InterMix\Exceptions\ContainerException;
use Infocyph\InterMix\Memoize\Memoizer;
use Infocyph\InterMix\Remix\TapProxy;

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
     * @throws Exception|InvalidArgumentException|\Psr\Cache\InvalidArgumentException
     */
    function container(
        string|Closure|callable|array|null $closureOrClass = null,
        string $alias = 'default',
    ): mixed {
        $instance = Container::instance($alias);

        if ($closureOrClass === null) {
            return $instance;
        }

        //    "class@method", "class::method", [class, method], or closure/callable.
        [$class, $method] = $instance
            ->split($closureOrClass);

        if (!$method) {
            if ($class instanceof Closure || is_callable($class)) {
                return $instance->invocation()->call($class);
            }

            return $instance->get($class);
        }

        $instance->registration()->registerMethod($class, $method);

        return $instance->getReturn($class);
    }
}

if (!function_exists('memoize')) {
    /**
     * Global memoization: caches a callable once for the entire process.
     *
     * If $callable is null, returns the Memoizer instance.
     *
     * @param callable|null $callable The function to memoize.
     * @param array $params The parameters to pass the callable (optional).
     *
     * @return mixed The result of the memoized callable.
     * @throws Exception
     */
    function memoize(callable $callable = null, array $params = []): mixed
    {
        $m = Memoizer::instance();
        if ($callable === null) {
            return $m;
        }
        return $m->get($callable, $params);
    }
}

if (!function_exists('remember')) {
    /**
     * Object-scoped memoization: caches a callable once per instance.
     *
     * @param object|null $object $object The object to scope the cache for.
     * @param callable|null $callable $callable The function to memoize.
     * @param array $params The parameters to pass the callable (optional).
     *
     * @return mixed The result of the memoized callable.
     *
     * @throws Exception
     */
    function remember(object $object = null, callable $callable = null, array $params = []): mixed
    {
        $m = Memoizer::instance();

        if ($object === null) {
            return $m;
        }
        if ($callable === null) {
            throw new InvalidArgumentException('remember() requires both object and callable');
        }
        return $m->getFor($object, $callable, $params);
    }
}

if (!function_exists('tap')) {
    /**
     * Pass the given value to the callback and return the value.
     *
     * If no callback is provided, returns a TapProxy that allows method chaining on the value.
     *
     * @param mixed $value The value to be passed to the callback.
     * @param callable|null $callback The callback to execute with the value (optional).
     * @return mixed The original value after the callback is applied, or a TapProxy if no callback is given.
     */
    function tap(mixed $value, ?callable $callback = null): mixed
    {
        if (is_null($callback)) {
            return new TapProxy($value);
        }
        $callback($value);
        return $value;
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
if (!function_exists('once')) {
    /**
     * Execute the given zero‐argument callback once at this call site (file:line).
     * On subsequent calls from the same file and line, return the cached result.
     *
     * @param callable $callback A zero‐argument function to run once.
     * @param Container|null $container
     * @return mixed The callback’s return value (cached on repeat calls).
     * @throws ContainerException
     * @throws \Psr\Cache\InvalidArgumentException
     */
    function once(callable $callback, ?Container $container = null): mixed
    {
        static $cache = [];
        $bt = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 2);
        $key = ($bt[1]['file'] ?? '(unknown)') . ':' . ($bt[1]['line'] ?? 0);

        if ($container !== null) {
            if (!$container->has($key)) {
                $container->registration()->registerClosure($key, $callback);
            }

            return $container->get($key);
        }

        if (array_key_exists($key, $cache)) {
            return $cache[$key];
        }

        $value = $callback();
        $cache[$key] = $value;
        return $value;
    }
}
