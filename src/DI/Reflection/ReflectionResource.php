<?php

namespace Infocyph\InterMix\DI\Reflection;

use Closure;
use InvalidArgumentException;
use ReflectionClass;
use ReflectionException;
use ReflectionFunction;
use ReflectionMethod;

class ReflectionResource
{
    /**
     * Returns the signature of the given reflection object.
     *
     * @param ReflectionClass|ReflectionMethod|ReflectionFunction $reflection The reflection object.
     * @return string The signature of the reflection object.
     */
    public static function getSignature(
        ReflectionClass|ReflectionMethod|ReflectionFunction $reflection
    ): string {
        return base64_encode("{$reflection->getFileName()}:{$reflection->getStartLine()}");
    }

    /**
     * Returns a ReflectionClass instance for the given class or object.
     *
     * @param string|object $class The name of the class or an object instance.
     * @return ReflectionClass A ReflectionClass instance for the given class or object.
     * @throws ReflectionException
     */
    public static function getForClass(string|object $class): ReflectionClass
    {
        return new ReflectionClass($class);
    }

    /**
     * Retrieves the reflection of a callable function or method.
     *
     * @param callable|array|string $callable The callable function or method.
     * @return ReflectionMethod|ReflectionFunction The reflection of the callable function or method.
     * @throws InvalidArgumentException If the method does not exist/the callable formation is unknown or invalid.
     * @throws ReflectionException
     */
    public static function getForFunction(callable|array|string $callable): ReflectionMethod|ReflectionFunction
    {
        if ($callable instanceof Closure || (is_string($callable) && function_exists($callable))) {
            return new ReflectionFunction($callable);
        }

        if (is_array($callable)) {
            [$class, $method] = $callable;

            if (!method_exists($class, $method)) {
                throw new InvalidArgumentException("'$method' doesn't exists in '$class'");
            }

            return new ReflectionMethod($class, $method);
        }

        if (is_object($callable) && method_exists($callable, '__invoke')) {
            return new ReflectionMethod($callable, '__invoke');
        }

        throw new InvalidArgumentException("Unknown/Invalid callable formation");
    }
}
