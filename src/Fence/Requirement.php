<?php


namespace AbmmHasan\OOF\Fence;


use Exception;

trait Requirement
{
    use Common;

    protected static $instance;

    /**
     * Creates a new instance of a singleton class if the
     * requirements are fulfilled
     *
     * @param array|null $constraints
     * @return self
     * @throws Exception
     */
    final public static function instance(array $constraints = null): static
    {
        static::checkRequirements($constraints);

        if (!static::$instance) {
            static::$instance = new static;
        }

        return static::$instance;
    }
}
