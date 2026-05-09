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
use Throwable;
use UnitEnum;

/**
 * Utility class for managing reflection operations with caching.
 */
final class ReflectionResource
{
    /**
     * @var array{
     *   classes: array<class-string, ReflectionClass<object>>,
     *   enums: array<class-string, ReflectionEnum<UnitEnum>>,
     *   functions: array<string, ReflectionFunction>,
     *   methods: array<string, ReflectionMethod>
     * }
     */
    private static array $reflectionCache = [
        'classes' => [],
        'enums' => [],
        'functions' => [],
        'methods' => [],
    ];

    public static function clearCache(): void
    {
        self::$reflectionCache = [
            'classes' => [],
            'enums' => [],
            'functions' => [],
            'methods' => [],
        ];
    }

    /**
     * @param callable|array{0: object|string, 1: string}|string|object $callable
     *
     * @throws InvalidArgumentException|ReflectionException
     */
    public static function getCallableReflection(
        callable|array|string|object $callable,
    ): ReflectionMethod|ReflectionFunction {
        if ($callable instanceof Closure) {
            return self::getFunctionReflection($callable);
        }

        if (is_string($callable)) {
            return self::resolveStringCallable($callable);
        }

        if (is_array($callable)) {
            if (count($callable) !== 2
                || (!is_string($callable[0]) && !is_object($callable[0]))
                || !is_string($callable[1])
            ) {
                throw new InvalidArgumentException('Invalid callable provided.');
            }

            return self::resolveArrayCallable($callable);
        }

        if (is_object($callable)) {
            return self::resolveObjectCallable($callable);
        }

        throw new InvalidArgumentException('Invalid callable provided.');
    }

    /**
     * @return ReflectionClass<object>
     *
     * @throws ReflectionException
     */
    public static function getClassReflection(string|object $class): ReflectionClass
    {
        $className = is_object($class) ? $class::class : $class;
        if (!class_exists($className) && !interface_exists($className)) {
            throw new ReflectionException("Class '$className' not found.");
        }

        if (!isset(self::$reflectionCache['classes'][$className])) {
            self::$reflectionCache['classes'][$className] = new ReflectionClass($className);
        }

        return self::$reflectionCache['classes'][$className];
    }

    /**
     * @return ReflectionEnum<UnitEnum>
     *
     * @throws ReflectionException
     */
    public static function getEnumReflection(string $enumName): ReflectionEnum
    {
        if (!enum_exists($enumName)) {
            throw new ReflectionException("Enum '$enumName' not found.");
        }

        if (!isset(self::$reflectionCache['enums'][$enumName])) {
            self::$reflectionCache['enums'][$enumName] = new ReflectionEnum($enumName);
        }

        return self::$reflectionCache['enums'][$enumName];
    }

    public static function getFunctionReflection(string|Closure $function): ReflectionFunction
    {
        $key = is_string($function) ? $function : spl_object_hash($function);
        if (!isset(self::$reflectionCache['functions'][$key])) {
            self::$reflectionCache['functions'][$key] = new ReflectionFunction($function);
        }

        return self::$reflectionCache['functions'][$key];
    }

    /**
     * @param string|object|callable|array{0: object|string, 1: string} $subject
     * @return ReflectionClass<object>|ReflectionEnum<UnitEnum>|ReflectionFunction|ReflectionMethod
     *
     * @throws InvalidArgumentException|ReflectionException
     */
    public static function getReflection(
        string|object|array|callable $subject,
    ): ReflectionClass|ReflectionEnum|ReflectionFunction|ReflectionMethod {
        if ($subject instanceof Closure) {
            return self::getFunctionReflection($subject);
        }

        if (is_callable($subject)) {
            return self::getCallableReflection($subject);
        }

        if (is_array($subject)) {
            return self::getCallableReflection($subject);
        }

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

        throw new InvalidArgumentException('Invalid reflection subject.');
    }

    /**
     * @param ReflectionClass<object>|ReflectionEnum<UnitEnum>|ReflectionFunctionAbstract $reflection
     */
    public static function getSignature(
        ReflectionClass|ReflectionEnum|ReflectionFunctionAbstract $reflection,
    ): string {
        $fileName = $reflection->getFileName() ?: 'unknown';
        $startLine = $reflection instanceof ReflectionEnum ? 0 : ($reflection->getStartLine() ?: 0);

        return base64_encode("$fileName:$startLine");
    }

    /**
     * @param callable(): ReflectionMethod $resolver
     */
    private static function rememberMethod(string $key, callable $resolver): ReflectionMethod
    {
        if (!isset(self::$reflectionCache['methods'][$key])) {
            try {
                $method = $resolver();
                self::$reflectionCache['methods'][$key] = $method;
            } catch (Throwable $exception) {
                throw new InvalidArgumentException($exception->getMessage(), 0, $exception);
            }
        }

        return self::$reflectionCache['methods'][$key];
    }

    /**
     * @param array{0: object|string, 1: string} $callable
     *
     * @throws InvalidArgumentException
     */
    private static function resolveArrayCallable(array $callable): ReflectionMethod
    {
        [$class, $method] = $callable;
        $className = is_object($class) ? $class::class : $class;
        if (!method_exists($class, $method)) {
            throw new InvalidArgumentException("Method '$method' does not exist in class '$className'.");
        }

        $key = "$className::$method";

        return self::rememberMethod($key, static fn(): ReflectionMethod => new ReflectionMethod($class, $method));
    }

    /**
     * @throws InvalidArgumentException
     */
    private static function resolveObjectCallable(object $callable): ReflectionMethod
    {
        if (!method_exists($callable, '__invoke')) {
            throw new InvalidArgumentException('Object does not have an __invoke method.');
        }

        $className = $callable::class;
        $key = "$className::__invoke";

        return self::rememberMethod($key, static fn(): ReflectionMethod => new ReflectionMethod($callable, '__invoke'));
    }

    /**
     * @throws InvalidArgumentException
     */
    private static function resolveStaticMethodCallable(string $callable): ReflectionMethod
    {
        [$className, $method] = explode('::', $callable, 2);
        if (!method_exists($className, $method)) {
            throw new InvalidArgumentException("Method '$method' does not exist in class '$className'.");
        }

        $key = "$className::$method";

        return self::rememberMethod($key, static fn(): ReflectionMethod => new ReflectionMethod($className, $method));
    }

    /**
     * @throws InvalidArgumentException|ReflectionException
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
}
