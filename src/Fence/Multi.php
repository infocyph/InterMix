<?php


namespace AbmmHasan\OOF\Fence;


use Exception;

trait Multi
{

    use Common;

    protected static array $__instances = [];

    /**
     * Creates a new instance of a class flagged with a key.
     *
     * @param string $key the key which the instance should be stored/retrieved
     * @param array|null $constraints
     * @return self
     * @throws Exception
     */
    final public static function __getInstance(string $key, array $constraints = null): static
    {
        static::__checkRequirements($constraints);

        if (!array_key_exists($key, static::$__instances)) {
            static::$__instances[$key] = new static;
        }
        return static::$__instances[$key];
    }
}