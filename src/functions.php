<?php

use AbmmHasan\OOF\DI\Container;

if (!function_exists('container')) {
    /**
     * Determine if the given value is callable, but not a string.
     *
     * @param string $alias
     * @param string|Closure|null $closureOrClass
     * @return mixed
     * @throws ReflectionException
     */
    function container(string $alias = 'oof', string|Closure $closureOrClass = null): mixed
    {
        $instance = Container::instance($alias);
        return match (true) {
            $closureOrClass === null => $instance,
            $closureOrClass instanceof Closure => $instance->callClosure($closureOrClass),
            default => $instance->callMethod($closureOrClass)
        };
    }
}