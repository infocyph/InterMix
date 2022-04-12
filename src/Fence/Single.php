<?php


namespace AbmmHasan\OOF\Fence;


use Exception;

trait Single
{
    use Common;

    protected static $instance;

    /**
     * Creates a new instance of a singleton class (via late static binding),
     * accepting a variable-length argument list.
     *
     * @param array|null $constraints
     * @return self
     * @throws Exception
     */
    final public static function getInstance(array $constraints = null): static
    {
        static::checkRequirements($constraints);

        if (!static::$instance) {
            static::$instance = new static;
        }
        return static::$instance;
    }
}
