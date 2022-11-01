<?php


namespace AbmmHasan\OOF\Fence;


use Exception;

trait Multi
{
    use Common;

    protected static array $instances = [];

    /**
     * Creates a new instance of a class flagged with a key.
     *
     * @param string $key the key which the instance should be stored/retrieved
     * @param array|null $constraints
     * @return self
     * @throws Exception
     */
    final public static function instance(string $key = 'default', array $constraints = null): static
    {
        static::checkRequirements($constraints);

        return static::$instances[$key] ??= new static;
    }
}
