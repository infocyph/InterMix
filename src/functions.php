<?php

declare(strict_types=1);

use Infocyph\InterMix\Remix\TapProxy;

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
        $t0 = hrtime(true);
        $out = $fn();
        $ms = (float) ((hrtime(true) - $t0) / 1_000_000);

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
