<?php


namespace AbmmHasan\OOF\Tonic;


use Exception;

trait Limit
{

    use Common;

    private static array $__instances = [];

    public static int $limit = 2;

    /**
     * Creates a new instance of a class flagged with a key.
     *
     * @param string $key the key which the instance should be stored/retrieved
     * @param array|null $constraints
     * @return self
     * @throws Exception
     */
    final public static function instance(string $key,array $constraints = null): static
    {
        static::__checkRequirements($constraints);

        if (!array_key_exists($key, static::$__instances)) {
            if (count(static::$__instances) < static::$limit) {
                static::$__instances[$key] = new static;
            }
        }
        return static::$__instances[$key];
    }

    /**
     * Sets the maximum number of instances the class allows
     *
     * @param $number int number of instances allowed
     * @return void
     */
    public function setLimit(int $number)
    {
        static::$limit = $number;
    }
}
