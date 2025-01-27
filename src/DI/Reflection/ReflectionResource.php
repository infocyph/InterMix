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

final class ReflectionResource
{
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
     * Clear the reflection cache.
     *
     * This will clear the statically cached reflection objects. This is useful
     * in tests or when you want to force re-reflection of classes, methods,
     * functions, or enums.
     */
    public static function clearCache(): void
    {
        self::$reflectionCache = [
            'classes'   => [],
            'enums'     => [],
            'functions' => [],
            'methods'   => [],
        ];
    }

    /*--------------------------------------------------------------------------
     |  Methods to retrieve reflection objects, with caching
     *-------------------------------------------------------------------------*/


    /**
     * Retrieves a ReflectionClass instance for the given class or object.
     *
     * @param string|object $class The class name or an object instance to reflect.
     * @return ReflectionClass The ReflectionClass instance representing the specified class.
     * @throws ReflectionException If the class does not exist or cannot be reflected.
     */
    public static function getClassReflection(string|object $class): ReflectionClass
    {
        $className = is_object($class) ? $class::class : $class;

        return self::$reflectionCache['classes'][$className]
            ??= new ReflectionClass($class);
    }


    /**
     * Retrieves a ReflectionEnum instance for the given enum name.
     *
     * @param string $enumName The name of the enum to reflect.
     * @return ReflectionEnum The ReflectionEnum instance representing the specified enum.
     * @throws ReflectionException If the enum does not exist or cannot be reflected.
     */
    public static function getEnumReflection(string $enumName): ReflectionEnum
    {
        return self::$reflectionCache['enums'][$enumName]
            ??= new ReflectionEnum($enumName);
    }


    /**
     * Retrieves a ReflectionFunction instance for the given function or closure.
     *
     * This method supports both named functions and closures. For named functions,
     * the function name is used as the cache key. For closures, the cache key is
     * obtained using spl_object_hash() to ensure each closure has a unique key.
     *
     * @param string|Closure $function The name of the function or a closure instance to reflect.
     * @return ReflectionFunction The ReflectionFunction instance representing the specified function.
     * @throws ReflectionException If the function does not exist or cannot be reflected.
     */
    public static function getFunctionReflection(string|Closure $function): ReflectionFunction
    {
        // Distinguish closures from named functions
        $key = is_string($function) ? $function : spl_object_hash($function);

        return self::$reflectionCache['functions'][$key]
            ??= new ReflectionFunction($function);
    }


    /**
     * Retrieves a ReflectionMethod or ReflectionFunction instance for the given callable.
     *
     * Supported callables include:
     *
     * 1. Closures
     * 2. String-based functions (e.g. "strlen")
     * 3. Arrays of [class, method] (e.g. ["MyClass", "myMethod"])
     * 4. Objects with an __invoke() method
     *
     * @param callable|array|string $callable The callable to reflect.
     * @return ReflectionMethod|ReflectionFunction The reflection instance representing the callable.
     * @throws InvalidArgumentException If the callable is invalid or cannot be reflected.
     * @throws ReflectionException If the callable does not exist or cannot be reflected.
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

            return self::$reflectionCache['methods'][$key]
                ??= new ReflectionMethod($class, $method);
        }

        // (3) If object with __invoke
        if (is_object($callable) && method_exists($callable, '__invoke')) {
            $key = $callable::class.'::__invoke';
            return self::$reflectionCache['methods'][$key]
                ??= new ReflectionMethod($callable, '__invoke');
        }

        throw new InvalidArgumentException('Invalid callable provided.');
    }
}
