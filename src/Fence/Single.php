<?php


namespace AbmmHasan\OOF\Fence;


use Exception;

trait Single
{
    use Common;

    protected static $instance;

    /**
     * Creates a new instance of a singleton class,
     * accepting a variable-length argument list.
     *
     * @param array|null $constraints
     * @return self
     * @throws Exception
     */
    final public static function instance(array $constraints = null): static
    {
        static::checkRequirements($constraints);

        return static::$instance ??= new static;
    }
}
