<?php


namespace AbmmHasan\InterMix\Fence;


use Exception;

trait Multi
{
    use Common;

    protected static array $instances = [];

    /**
     * Creates a new instance of a class flagged with a key
     *
     * @param string $key The key to identify the instance (default: 'default').
     * @param array|null $constraints The constraints to check (default: null).
     * @return static The instance of the class.
     * @throws Exception A description of the exception that can be thrown.
     */
    final public static function instance(string $key = 'default', array $constraints = null): static
    {
        static::checkRequirements($constraints);

        return static::$instances[$key] ??= new static;
    }
}
