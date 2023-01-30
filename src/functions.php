<?php

use AbmmHasan\OOF\DI\Container;
use AbmmHasan\OOF\Exceptions\ContainerException;

if (!function_exists('container')) {
    /**
     * Get Container instance or direct call method/closure
     *
     * @param string $alias instance alias
     * @param string|Closure|callable|array|null $closureOrClass
     * @return Container|mixed
     * @throws ContainerException
     */
    function container(string $alias = 'oof', string|Closure|callable|array $closureOrClass = null)
    {
        $instance = Container::instance($alias);
        return match (true) {
            $closureOrClass === null => $instance,
            default => $instance->call(...$instance->split($closureOrClass))
        };
    }
}
