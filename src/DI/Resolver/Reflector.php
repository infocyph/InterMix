<?php

namespace AbmmHasan\InterMix\DI\Resolver;

use Closure;
use ReflectionClass;
use ReflectionException;
use ReflectionFunction;

trait Reflector
{
    /**
     * Get ReflectionClass instance
     *
     * @param string $className
     * @return ReflectionClass
     * @throws ReflectionException
     */
    private function reflectedClass(string $className): ReflectionClass
    {
        return $this->repository->resolvedResource[$className]['reflection'] ??= new ReflectionClass($className);
    }

    /**
     * Get ReflectionFunction instance
     *
     * @param string|Closure $closure
     * @param string $name
     * @return ReflectionFunction
     * @throws ReflectionException
     */
    private function reflectedFunction(string|Closure $closure, string $name): ReflectionFunction
    {
        return $this->repository->resolvedFunction[$name]['reflection'] ??= new ReflectionFunction($closure);
    }
}
