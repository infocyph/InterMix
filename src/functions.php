<?php

use AbmmHasan\InterMix\DI\Container;
use AbmmHasan\InterMix\Exceptions\ContainerException;
use AbmmHasan\InterMix\Exceptions\NotFoundException;

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
