<?php


namespace AbmmHasan\InterMix\Fence;


use Exception;

trait Single
{
    use Common;

    protected static $instance = null;

    /**
     * Creates and returns the only instance of the class.
     *
     * @param array|null $constraints The constraints for creating the instance.
     * @return static The created instance.
     * @throws Exception
     */
    final public static function instance(array $constraints = null): static
    {
        static::checkRequirements($constraints);

        return static::$instance ??= new static;
    }
}
