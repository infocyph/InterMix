<?php

declare(strict_types=1);

namespace Infocyph\InterMix\DI\Reflection;

use Closure;
use InvalidArgumentException;
use ReflectionClass;
use ReflectionEnum;
use ReflectionException;
use ReflectionFunction;
use ReflectionMethod;

/**
 * Centralized Reflection caching with optional thread safety.
 *
 * If $threadSafe is true, we do a simple lock around the static cache
 * to avoid concurrency issues in multi-threaded PHP.
 */
final class ReflectionResource
{
    private static bool $threadSafe = false;

    /**
     * Static reflection cache:
     * [
     *   'classes'   => [className => ReflectionClass],
     *   'enums'     => [enumName  => ReflectionEnum],
     *   'functions' => [fnKey     => ReflectionFunction],
     *   'methods'   => ["Class::method" => ReflectionMethod],
     * ]
     */
    private static array $reflectionCache = [
        'classes'   => [],
        'enums'     => [],
        'functions' => [],
        'methods'   => [],
    ];

    /**
     * If you run in multi-thread environment, you can call setThreadSafe(true).
     * This uses a static lock (like an ext-pcntl or pthreads approach).
     */
    public static function setThreadSafe(bool $enable): void
    {
        self::$threadSafe = $enable;
    }

    public static function isThreadSafe(): bool
    {
        return self::$threadSafe;
    }

    /**
     * Thread-safety approach: Acquire a lock if $threadSafe is true.
     * This is a simple placeholder; real code would use semaphores or ext-pthreads if needed.
     */
    private static function acquireLock(): void
    {
        if (!self::$threadSafe) {
            return;
        }
        // Placeholder: real code might do:
        // flock(self::$lockHandle, LOCK_EX);
    }

    /**
     * Release the lock if threadSafe.
     */
    private static function releaseLock(): void
    {
        if (!self::$threadSafe) {
            return;
        }
        // Placeholder: real code might do:
        // flock(self::$lockHandle, LOCK_UN);
    }

    /**
     * Clears all reflection cache (useful in tests or dev).
     */
    public static function clearCache(): void
    {
        self::acquireLock();
        try {
            self::$reflectionCache = [
                'classes'   => [],
                'enums'     => [],
                'functions' => [],
                'methods'   => [],
            ];
        } finally {
            self::releaseLock();
        }
    }

    /*--------------------------------------------------------------------------
     |  Methods to retrieve reflection objects, with caching
     *-------------------------------------------------------------------------*/

    /**
     * Returns a ReflectionClass instance for the given class or object.
     *
     * @throws ReflectionException
     */
    public static function getClassReflection(string|object $class): ReflectionClass
    {
        $className = is_object($class) ? $class::class : $class;

        self::acquireLock();
        try {
            return self::$reflectionCache['classes'][$className]
                ??= new ReflectionClass($class);
        } finally {
            self::releaseLock();
        }
    }

    /**
     * Returns a ReflectionEnum instance for the given enum.
     *
     * @throws ReflectionException
     */
    public static function getEnumReflection(string $enumName): ReflectionEnum
    {
        self::acquireLock();
        try {
            return self::$reflectionCache['enums'][$enumName]
                ??= new ReflectionEnum($enumName);
        } finally {
            self::releaseLock();
        }
    }

    /**
     * Returns a ReflectionFunction for the given function or closure.
     *
     * @throws ReflectionException
     */
    public static function getFunctionReflection(string|Closure $function): ReflectionFunction
    {
        // Distinguish closures from named functions
        $key = is_string($function) ? $function : spl_object_hash($function);

        self::acquireLock();
        try {
            return self::$reflectionCache['functions'][$key]
                ??= new ReflectionFunction($function);
        } finally {
            self::releaseLock();
        }
    }

    /**
     * Returns a ReflectionMethod for the given callable or array [class, method].
     *
     * @throws InvalidArgumentException|ReflectionException
     */
    public static function getCallableReflection(
        callable|array|string $callable
    ): ReflectionMethod|ReflectionFunction {
        // (1) If it's a closure or string-based function
        if ($callable instanceof Closure ||
            (is_string($callable) && function_exists($callable))
        ) {
            return self::getFunctionReflection($callable);
        }

        // (2) If it's [class, method]
        if (is_array($callable) && count($callable) === 2) {
            [$class, $method] = $callable;
            if (! method_exists($class, $method)) {
                throw new InvalidArgumentException("Method '$method' does not exist in class '$class'.");
            }
            $key = "$class::$method";

            self::acquireLock();
            try {
                return self::$reflectionCache['methods'][$key]
                    ??= new ReflectionMethod($class, $method);
            } finally {
                self::releaseLock();
            }
        }

        // (3) If object with __invoke
        if (is_object($callable) && method_exists($callable, '__invoke')) {
            $key = $callable::class.'::__invoke';
            self::acquireLock();
            try {
                return self::$reflectionCache['methods'][$key]
                    ??= new ReflectionMethod($callable, '__invoke');
            } finally {
                self::releaseLock();
            }
        }

        throw new InvalidArgumentException('Invalid callable provided.');
    }
}
