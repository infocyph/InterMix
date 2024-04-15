<?php

namespace Infocyph\InterMix;

use Infocyph\InterMix\DI\Container;
use Infocyph\InterMix\DI\Reflection\ReflectionResource;
use Infocyph\InterMix\Exceptions\{ContainerException, NotFoundException};
use Infocyph\InterMix\Memoize\Cache;
use Infocyph\InterMix\Memoize\WeakCache;
use Closure;
use Exception;
use Psr\Cache\InvalidArgumentException;
use ReflectionException;

if (!function_exists('Infocyph\InterMix\container')) {
    /**
     * Get Container instance or direct call method/closure
     *
     * @param string|Closure|callable|array|null $closureOrClass The closure, class, or callable array.
     * @param string $alias The alias for the container instance.
     * @return Container|mixed Container or The return value of the function.
     * @throws ContainerException|NotFoundException|InvalidArgumentException
     */
    function container(string|Closure|callable|array $closureOrClass = null, string $alias = 'default')
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

if (!function_exists('Infocyph\InterMix\memoize')) {
    /**
     * Retrieves a memoized value of the provided callable.
     *
     * @param callable|null $callable The callable to be memoized. Defaults to null.
     * @param array $parameters The parameters to be passed to the callable. Defaults to an empty array.
     * @return mixed The memoized result of the callable.
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

if (!function_exists('Infocyph\InterMix\remember')) {
    /**
     * Retrieves a memoized value based on the provided class object (till garbage collected)
     *
     * @param object|null $classObject The class object for which the value is being retrieved.
     * @param callable|null $callable The callable for which the value is being retrieved.
     * @param array $parameters The parameters for the callable.
     * @return mixed The memoized result of the callable.
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
