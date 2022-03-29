<?php


namespace AbmmHasan\OOF\Fence;


use Exception;

trait Requirement
{

    use Common;

    protected static $__instance;

    /**
     * Creates a new instance of a singleton class if the
     * requirements are fulfilled
     *
     * @param array|null $constraints
     * @return self
     * @throws Exception
     */
    final public static function __getInstance(array $constraints = null): static
    {
        static::__checkRequirements($constraints);

        if (!static::$__instance) {
            static::$__instance = new static;
        }

        return static::$__instance;
    }
}
