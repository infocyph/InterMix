<?php

use AbmmHasan\OOF\DI\Container;

if (!function_exists('initiate')) {
    /**
     * Shortcut to DI Container, for easy access
     *
     * @param $classOrClosure
     * @param mixed ...$parameters
     * @return Container
     */
    function initiate($classOrClosure, ...$parameters): Container
    {
        return new Container($classOrClosure, ...$parameters);
    }
}
