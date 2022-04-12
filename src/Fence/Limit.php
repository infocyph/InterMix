<?php


namespace AbmmHasan\OOF\Fence;


use Exception;

trait Limit
{

    use Common;

    protected static array $instances = [];

    protected static int $limit = 2;

    /**
     * Creates a new instance of a class flagged with a key.
     *
     * @param string $key the key which the instance should be stored/retrieved
     * @param array|null $constraints
     * @return self
     * @throws Exception
     */
    final public static function getInstance(string $key, array $constraints = null): static
    {
        static::checkRequirements($constraints);

        if (!array_key_exists($key, static::$instances)) {
            if (count(static::$instances) < static::$limit) {
                static::$instances[$key] = new static;
            }
        }
        return static::$instances[$key] ??
            throw new Exception('Initialization limit exceeded!');
    }

    /**
     * Sets the maximum number of instances the class allows
     *
     * @param $number int number of instances allowed
     * @return void
     */
    final public function setLimit(int $number)
    {
        static::$limit = $number;
    }
}
