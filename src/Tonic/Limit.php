<?php


namespace AbmmHasan\OOF\Tonic;


use Exception;

trait Limit
{

    use Common;

    private static $instances = [];

    public static $limit = 2;

    /**
     * Creates a new instance of a class flagged with a key.
     *
     * @param string $key the key which the instance should be stored/retrieved
     * @param array|null $constraints
     * @return self
     * @throws Exception
     */
    final public static function instance(string $key,array $constraints = null): Limit
    {
        self::__checkRequirements($constraints);

        if (!array_key_exists($key, self::$instances)) {
            if (count(self::$instances) < self::$limit) {
                self::$instances[$key] = new self;
            }
        }
        return self::$instances[$key];
    }

    /**
     * Sets the maximum number of instances the class allows
     *
     * @param $number int number of instances allowed
     * @return void
     */
    public function setLimit(int $number)
    {
        self::$limit = $number;
    }
}
