<?php


namespace AbmmHasan\InterMix\Fence;


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
    final public static function instance(string $key = 'default', array $constraints = null): static
    {
        static::checkRequirements($constraints);

        if (count(static::$instances) >= static::$limit) {
            throw new Exception('Initialization limit exceeded!');
        }

        return static::$instances[$key] ??= new static;
    }

    /**
     * Sets the maximum number of instances the class allows
     *
     * @param $number int number of instances allowed
     * @return void
     */
    final public function setLimit(int $number): void
    {
        static::$limit = $number;
    }
}
