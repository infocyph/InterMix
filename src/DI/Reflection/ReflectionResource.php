<?php

namespace AbmmHasan\InterMix\DI\Reflection;

use Closure;
use InvalidArgumentException;
use ReflectionClass;
use ReflectionException;
use ReflectionFunction;
use ReflectionMethod;

class ReflectionResource
{
    /**
     * Get an unique signature per reflection
     *
     * @param ReflectionClass|ReflectionMethod|ReflectionFunction $reflection
     * @return string
     */
    public static function getSignature(
        ReflectionClass|ReflectionMethod|ReflectionFunction $reflection
    ): string {
        return base64_encode("{$reflection->getFileName()}:{$reflection->getStartLine()}");
    }

    /**
     * Get class reflection
     *
     * @param string|object $class
     * @return ReflectionClass
     * @throws ReflectionException
     */
    public static function getForClass(string|object $class): ReflectionClass
    {
        return new ReflectionClass($class);
    }

    /**
     * Get reflection for function
     *
     * @param callable|array|string $callable
     * @return ReflectionMethod|ReflectionFunction
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
