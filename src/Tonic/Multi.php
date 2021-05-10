<?php


namespace AbmmHasan\OOF\Tonic;


use Exception;

trait Multi
{

    use Common;

    private static $instances = [];

    /**
     * Creates a new instance of a class flagged with a key.
     *
     * @param string $key the key which the instance should be stored/retrieved
     * @param array|null $constraints
     * @return self
     * @throws Exception
     */
    final public static function instance(string $key,array $constraints = null): Multi
    {
        self::__checkRequirements($constraints);

        if (!array_key_exists($key, self::$instances)) {
            self::$instances[$key] = new self;
        }
        return self::$instances[$key];
    }
}
