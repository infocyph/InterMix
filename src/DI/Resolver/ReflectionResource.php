<?php

namespace AbmmHasan\InterMix\DI\Resolver;

use ReflectionClass;
use ReflectionException;

trait ReflectionResource
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
}
