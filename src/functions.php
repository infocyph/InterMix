<?php

use AbmmHasan\OOF\DI\Container;

if (!function_exists('container')) {
    /**
     * Get Container instance or direct call method/closure
     *
     * @param string $alias instance alias
     * @param string|Closure|null $closureOrClass
     * @return Container|mixed
     * @throws Exception
     */
    function container(string $alias = 'oof', string|Closure $closureOrClass = null)
    {
        try {
            $instance = Container::instance($alias);
            return match (true) {
                $closureOrClass === null => $instance,
                $closureOrClass instanceof Closure => $instance->callClosure($closureOrClass),
                default => $instance->callMethod($closureOrClass)
            };
        } catch (ReflectionException|Exception $e) {
            throw new Exception($e->getMessage());
        }
    }
}