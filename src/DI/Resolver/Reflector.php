<?php

namespace AbmmHasan\InterMix\DI\Resolver;

use AbmmHasan\InterMix\DI\Reflection\ReflectionResource;
use Closure;
use ReflectionClass;
use ReflectionEnum;
use ReflectionException;
use ReflectionFunction;

trait Reflector
{
    /**
     * Returns a ReflectionClass object for the given class or object.
     *
     * @param string|object $class The class or object for which to retrieve the ReflectionClass.
     * @return ReflectionClass The ReflectionClass object for the given class or object.
     * @throws ReflectionException If the ReflectionClass cannot be obtained.
     */
    private function reflectedClass(string|object $class): ReflectionClass
    {
        return $this->repository->resolvedResource[$class]['reflection'] ??= ReflectionResource::getForClass($class);
    }

    /**
     * Returns a ReflectionEnum object based on the given name.
     *
     * @param string $name The name of the enum to reflect
     * @return ReflectionEnum The reflection object for the given enum name
     * @throws ReflectionException If the ReflectionClass cannot be obtained.
     */
    private function reflectedEnum(string $name): ReflectionEnum
    {
        return $this->repository->resolvedEnum[$name]['reflection'] ??= new ReflectionEnum($name);
    }

    /**
     * Returns a ReflectionFunction object based on the provided closure and name.
     *
     * @param string|Closure $closure The closure object or the name of the closure.
     * @param string $name The name of the closure.
     * @return ReflectionFunction The ReflectionFunction object.
     * @throws ReflectionException If the reflection of the closure fails.
     */
    private function reflectedFunction(string|Closure $closure, string $name): ReflectionFunction
    {
        return $this->repository->resolvedFunction[$name]['reflection'] ??= new ReflectionFunction($closure);
    }
}
