<?php

declare(strict_types=1);

namespace Infocyph\InterMix\DI\Support;

use Closure;
use InvalidArgumentException;
use ReflectionClass;
use ReflectionEnum;
use ReflectionException;
use ReflectionFunction;
use ReflectionFunctionAbstract;
use ReflectionMethod;

final class ReflectionResource
{
    private static array $reflectionCache = [
        'classes'   => [],
        'enums'     => [],
        'functions' => [],
        'methods'   => [],
    ];

    public static function clearCache(): void
    {
        self::$reflectionCache = [
            'classes'   => [],
            'enums'     => [],
            'functions' => [],
            'methods'   => [],
        ];
    }

    /**
     * Returns a unique string signature for the given Reflection object.
     *
     * The signature is a base64-encoded string of the file name and start line
     * of the reflection object. If the file name is unknown (e.g. for anonymous
     * classes), it is replaced with "unknown". For ReflectionEnum, the start line
     * is always 0.
     *
     * @param ReflectionClass|ReflectionEnum|ReflectionMethod|ReflectionFunction|ReflectionFunctionAbstract $reflection
     *     The reflection object to get the signature for.
     *
     * @return string The signature string.
     */
    public static function getSignature(
        ReflectionClass|ReflectionEnum|ReflectionMethod|ReflectionFunction|ReflectionFunctionAbstract $reflection
    ): string {
        $fileName = $reflection->getFileName() ?: 'unknown';
        $startLine = 0;
        if (!$reflection instanceof ReflectionEnum) {
            $startLine = $reflection->getStartLine() ?: 0;
        }

        return base64_encode("$fileName:$startLine");
    }

    /**
     * Gets a ReflectionClass for the given class name or object.
     *
     * The resulting ReflectionClass is cached by class name to avoid redundant lookups.
     * If the class does not exist, a ReflectionException is thrown.
     *
     * @param string|object $class The class name or object to get the ReflectionClass for.
     *
     * @return ReflectionClass The ReflectionClass for the given class.
     *
     * @throws ReflectionException If the class does not exist.
     */
    public static function getClassReflection(string|object $class): ReflectionClass
    {
        $className = is_object($class) ? $class::class : $class;

        $cache = & self::$reflectionCache['classes'];
        if (!isset($cache[$className])) {
            $cache[$className] = new ReflectionClass($class);
        }
        return $cache[$className];
    }

    /**
     * Gets a ReflectionEnum for the given enum name.
     *
     * The resulting ReflectionEnum is cached by enum name to avoid redundant lookups.
     * If the enum does not exist, a ReflectionException is thrown.
     *
     * @param string $enumName The enum name to get the ReflectionEnum for.
     *
     * @return ReflectionEnum The ReflectionEnum for the given enum.
     *
     * @throws ReflectionException If the enum does not exist.
     */
    public static function getEnumReflection(string $enumName): ReflectionEnum
    {
        return self::$reflectionCache['enums'][$enumName]
            ??= new ReflectionEnum($enumName);
    }

    /**
     * Gets a ReflectionFunction for the given function name or Closure.
     *
     * The resulting ReflectionFunction is cached by function name or
     * Closure object hash to avoid redundant lookups. If the function
     * does not exist, a ReflectionException is thrown.
     *
     * @param string|Closure $function The function name or Closure to get the ReflectionFunction for.
     *
     * @return ReflectionFunction The ReflectionFunction for the given function.
     *
     * @throws ReflectionException If the function does not exist.
     */
    public static function getFunctionReflection(string|Closure $function): ReflectionFunction
    {
        $key = is_string($function) ? $function : spl_object_hash($function);

        return self::$reflectionCache['functions'][$key]
            ??= new ReflectionFunction($function);
    }

    /**
     * Gets a ReflectionMethod or ReflectionFunction for the given callable.
     *
     * The given callable can be a string function or method name, an array
     * of class and method name, an object with an __invoke method, or a
     * Closure. If the callable does not exist, an InvalidArgumentException
     * is thrown.
     *
     * @param callable|array|string|object $callable The callable to get the
     *     ReflectionMethod or ReflectionFunction for.
     *
     * @return ReflectionMethod|ReflectionFunction The ReflectionMethod or
     *     ReflectionFunction for the given callable.
     *
     * @throws InvalidArgumentException|ReflectionException If the callable does not exist.
     */
    public static function getCallableReflection(callable|array|string|object $callable): ReflectionMethod|ReflectionFunction
    {
        if ($callable instanceof Closure) {
            return self::getFunctionReflection($callable);
        }

        if (is_string($callable)) {
            return self::resolveStringCallable($callable);
        }

        if (is_array($callable) && count($callable) === 2) {
            return self::resolveArrayCallable($callable);
        }

        if (is_object($callable)) {
            return self::resolveObjectCallable($callable);
        }

        throw new InvalidArgumentException('Invalid callable provided.');
    }

    /**
     * Resolves a string callable to a ReflectionMethod or ReflectionFunction.
     *
     * The given string callable can be a function name, a static method call
     * in the form of "ClassName::methodName", or an instance method call in
     * the form of "$object->methodName".
     *
     * If the callable is a function, it is resolved using
     * ReflectionResource::getFunctionReflection.
     *
     * If the callable is a static method call, it is resolved using
     * ReflectionResource::resolveStaticMethodCallable.
     *
     * If the callable is an instance method call, it is resolved using
     * ReflectionResource::resolveObjectCallable.
     *
     * If the callable does not exist, an InvalidArgumentException is thrown.
     *
     * @param string $callable The string callable to resolve.
     *
     * @return ReflectionMethod|ReflectionFunction The resolved ReflectionMethod or ReflectionFunction.
     *
     * @throws InvalidArgumentException|ReflectionException If the callable does not exist.
     */
    private static function resolveStringCallable(string $callable): ReflectionMethod|ReflectionFunction
    {
        if (function_exists($callable)) {
            return self::getFunctionReflection($callable);
        }

        if (str_contains($callable, '::')) {
            return self::resolveStaticMethodCallable($callable);
        }

        throw new InvalidArgumentException("Function or method '$callable' does not exist.");
    }

    /**
     * Resolves a static method callable to a ReflectionMethod.
     *
     * The given string callable is expected to be in the form of
     * "ClassName::methodName".
     *
     * If the method does not exist, an InvalidArgumentException is thrown.
     *
     * @param string $callable The string callable to resolve.
     *
     * @return ReflectionMethod The resolved ReflectionMethod.
     *
     * @throws InvalidArgumentException If the callable does not exist.
     */
    private static function resolveStaticMethodCallable(string $callable): ReflectionMethod
    {
        [$className, $method] = explode('::', $callable, 2);

        if (!method_exists($className, $method)) {
            throw new InvalidArgumentException("Method '$method' does not exist in class '$className'.");
        }

        $key = "$className::$method";

        return self::$reflectionCache['methods'][$key]
            ??= new ReflectionMethod($className, $method);
    }

    /**
     * Resolves an array callable to a ReflectionMethod.
     *
     * The given array callable should consist of two elements: a class name
     * or an object instance, and a method name. The method must exist in
     * the specified class or object.
     *
     * The resolved ReflectionMethod is cached by the class and method name
     * to avoid redundant lookups.
     *
     * @param array $callable An array consisting of a class or object and a method name.
     * @return ReflectionMethod The resolved ReflectionMethod.
     * @throws InvalidArgumentException If the method does not exist in the class or object.
     */
    private static function resolveArrayCallable(array $callable): ReflectionMethod
    {
        [$class, $method] = $callable;
        $className = is_object($class) ? $class::class : $class;

        if (!method_exists($class, $method)) {
            throw new InvalidArgumentException("Method '$method' does not exist in class '$className'.");
        }

        $key = "$className::$method";

        return self::$reflectionCache['methods'][$key]
            ??= new ReflectionMethod($class, $method);
    }

    /**
     * Resolves an object callable to a ReflectionMethod.
     *
     * The given object must have an __invoke method, which is the method
     * that is called when the object is treated as a callable. The method
     * must exist in the object's class.
     *
     * The resolved ReflectionMethod is cached by the class name and
     * method name to avoid redundant lookups.
     *
     * @param object $callable An object with an __invoke method.
     * @return ReflectionMethod The resolved ReflectionMethod.
     * @throws InvalidArgumentException If the object does not have an __invoke method.
     */
    private static function resolveObjectCallable(object $callable): ReflectionMethod
    {
        if (method_exists($callable, '__invoke')) {
            $className = $callable::class;
            $key = "$className::__invoke";

            return self::$reflectionCache['methods'][$key]
                ??= new ReflectionMethod($callable, '__invoke');
        }

        throw new InvalidArgumentException('Object does not have an __invoke method.');
    }

    /**
     * Resolves a given subject to a reflection object.
     *
     * The given subject can be a string (class name, function name, enum name, etc.),
     * an object (class instance), an array (callable), or a callable (function, method, etc.).
     *
     * The resolved reflection object is one of the following types:
     * - ReflectionClass (for a class)
     * - ReflectionEnum (for an enum)
     * - ReflectionFunction (for a function)
     * - ReflectionMethod (for a method)
     *
     * If the given subject is invalid, an InvalidArgumentException is thrown.
     *
     * @param string|object|array|callable $subject The subject to resolve.
     *
     * @return ReflectionClass|ReflectionEnum|ReflectionFunction|ReflectionMethod The resolved reflection object.
     *
     * @throws InvalidArgumentException|ReflectionException If the given subject is invalid.
     */
    public static function getReflection(
        string|object|array|callable $subject
    ): ReflectionClass|ReflectionEnum|ReflectionFunction|ReflectionMethod {
        if ($subject instanceof Closure) {
            return self::getFunctionReflection($subject);
        }

        if (is_callable($subject)) {
            return self::getCallableReflection($subject);
        }

        if (is_string($subject) || is_object($subject)) {
            $className = is_object($subject) ? $subject::class : $subject;

            if (enum_exists($className)) {
                return self::getEnumReflection($className);
            }

            if (class_exists($className)) {
                return self::getClassReflection($subject);
            }

            if (is_string($subject) && function_exists($subject)) {
                return self::getFunctionReflection($subject);
            }
        }

        throw new InvalidArgumentException("Invalid reflection subject.");
    }
}
