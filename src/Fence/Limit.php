<?php


namespace AbmmHasan\OOF\Fence;


use Exception;

trait Limit
{

    use Common;

    protected static array $__instances = [];

    protected static int $limit = 2;

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
            if (count(static::$__instances) < static::$limit) {
                static::$__instances[$key] = new static;
            }
        }
        return static::$__instances[$key] ??
            throw new Exception('Initialization limit exceeded!');
    }

    /**
     * Sets the maximum number of instances the class allows
     *
     * @param $number int number of instances allowed
     * @return void
     */
    final public function __setLimit(int $number)
    {
        static::$limit = $number;
    }
}
