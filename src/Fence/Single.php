<?php


namespace AbmmHasan\OOF\Fence;


use Exception;

trait Single
{
    use Common;

    protected static $__instance;

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
        static::__checkRequirements($constraints);

        if (!static::$__instance) {
            static::$__instance = new static;
        }
        return static::$__instance;
    }
}
