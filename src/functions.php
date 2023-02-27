<?php

use AbmmHasan\InterMix\DI\Container;
use AbmmHasan\InterMix\DI\Reflection\ReflectionResource;
use AbmmHasan\InterMix\Exceptions\{ContainerException, NotFoundException};
use AbmmHasan\InterMix\Memoize\Cache;
use AbmmHasan\InterMix\Memoize\WeakCache;

if (!function_exists('container')) {
    /**
     * Get Container instance or direct call method/closure
     *
     * @param string|Closure|callable|array|null $closureOrClass
     * @param string $alias instance alias
     * @return Container|mixed
     * @throws ContainerException|NotFoundException
     */
    function container(string|Closure|callable|array $closureOrClass = null, string $alias = 'inter_mix')
    {
        $instance = Container::instance($alias);
        if ($closureOrClass === null) {
            return $instance;
        }

        [$class, $method] = $instance->split($closureOrClass);
        if (!$method) {
            return $instance->get($class);
        }

        $instance->registerMethod($class, $method);
        return $instance->getReturn($class);
    }
}

if (!function_exists('memoize')) {
    /**
     * Memoize a function return during a process
     *
     * @param callable|null $callable callable
     * @param array $parameters
     * @return mixed
     * @throws ReflectionException|Exception
     */
    function memoize(callable $callable = null, array $parameters = []): mixed
    {
        if ($callable === null) {
            return Cache::instance();
        }
        return (Cache::instance())->get(
            ReflectionResource::getSignature(ReflectionResource::getForFunction($callable)),
            $callable,
            $parameters
        );
    }
}

if (!function_exists('remember')) {
    /**
     * Memoize a function return till the class object is destroyed/garbage collected
     *
     * @param object|null $classObject $this
     * @param callable|null $callable callable
     * @param array $parameters
     * @return mixed
     * @throws ReflectionException|Exception
     */
    function remember(object $classObject = null, callable $callable = null, array $parameters = []): mixed
    {
        if ($classObject === null) {
            return WeakCache::instance();
        }
        return (WeakCache::instance())->get(
            $classObject,
            ReflectionResource::getSignature(ReflectionResource::getForFunction($callable)),
            $callable,
            $parameters
        );
    }
}
