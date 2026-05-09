<?php

declare(strict_types=1);

use Infocyph\InterMix\DI\Container;
use Infocyph\InterMix\Exceptions\ContainerException;
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
     * @param array{0:string,1:string}|null|string|Closure|callable $closureOrClass
     */
    function container(
        string|Closure|callable|array|null $closureOrClass = null,
        string $alias = __DIR__,
    ): mixed {
        $instance = Container::instance($alias);

        return $closureOrClass === null
            ? $instance
            : $instance->resolveNow($closureOrClass);
    }
}

if (!function_exists('resolve')) {
    /**
     * Resolve a callable/class immediately with DI enabled.
     *
     * @param string|Closure|callable|array|null $spec Closure|function|Class|[Class,method]|"Class@method"|"Class::method"
     * @param array<int|string, mixed> $parameters Parameters for constructor/method/closure
     * @param string $alias Optional container instance alias (default: __DIR__)
     * @return mixed Container instance (when $spec is null) or resolved return value
     * @throws ContainerException|ReflectionException|InvalidArgumentException
     * @param array{0:string,1:string}|null|string|Closure|callable $spec
     */
    function resolve(
        string|Closure|callable|array|null $spec = null,
        array $parameters = [],
        string $alias = __DIR__ . 'DI',
    ): mixed {
        $instance = Container::instance($alias); // DI is on by default

        return $spec === null
            ? $instance
            : $instance->resolveNow($spec, $parameters);
    }
}

if (!function_exists('direct')) {
    /**
     * Resolve a callable/class immediately with DI disabled (no injection).
     *
     * @param string|Closure|callable|array|null $spec Closure|function|Class|[Class,method]|"Class@method"|"Class::method"
     * @param array<int|string, mixed> $parameters Parameters for constructor/method/closure
     * @param string $alias Optional container instance alias (default: __DIR__)
     * @return mixed Container instance (when $spec is null) or resolved return value
     * @throws ContainerException|ReflectionException|InvalidArgumentException
     * @param array{0:string,1:string}|null|string|Closure|callable $spec
     */
    function direct(
        string|Closure|callable|array|null $spec = null,
        array $parameters = [],
        string $alias = __DIR__ . 'DR',
    ): mixed {
        $instance = Container::instance($alias)
            ->options()
            ->setOptions(false)
            ->end();

        return $spec === null
            ? $instance
            : $instance->resolveNow($spec, $parameters);
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
        if ($value) {
            return $truthy($value);
        }

        return $falsy ? $falsy($value) : $value;
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
     *                        Passed by reference and will be updated with the elapsed time.
     *                        Defaults to null if not provided.
     * @param-out float $ms
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
     *                                   returns true if the operation should be retried, false otherwise.
     * @param int $delayMs The base delay to sleep between retries, in milliseconds.
     * @param float $backoff The backoff factor to apply to the delay after each retry.
     *                       Defaults to 1.0 (no backoff).  For example, a value of 2.0 will double the
     *                       delay after each retry.
     *
     * @return mixed The result of the callback, if it succeeds.
     * @throws Throwable The exception that was thrown by the callback on the last
     *                   attempt, if it never succeeds.
     */
    function retry(
        int $attempts,
        callable $callback,
        ?callable $shouldRetry = null,
        int $delayMs = 0,
        float $backoff = 1.0,
    ): mixed {
        if ($attempts < 1) {
            throw new InvalidArgumentException('Attempts must be at least 1.');
        }

        $sleep = max(0, $delayMs);
        for ($tries = 1; $tries <= $attempts; $tries++) {
            try {
                return $callback($tries);
            } catch (Throwable $e) {
                if ($tries >= $attempts || ($shouldRetry && !$shouldRetry($e))) {
                    throw $e;
                }
            }

            if ($sleep > 0) {
                usleep($sleep * 1000);
                $sleep = (int) ($sleep * max($backoff, 0.0));
            }
        }

        throw new \RuntimeException('Retry loop exited unexpectedly.');
    }
}
