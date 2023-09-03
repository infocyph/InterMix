<?php


namespace AbmmHasan\InterMix\Fence;


use Exception;

trait Limit
{
    use Common;

    protected static array $instances = [];

    protected static int $limit = 2;

    /**
     * Creates a new instance of the class.
     *
     * @param string $key The key for the instance. Default is 'default'.
     * @param array|null $constraints An optional array of constraints.
     * @return static The new instance of the class.
     * @throws Exception If the initialization limit has been exceeded.
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
     * Sets the maximum number of instances allowed.
     *
     * @param int $number The number to set as the limit.
     * @return void
     */
    final public function setLimit(int $number): void
    {
        static::$limit = $number;
    }
}
