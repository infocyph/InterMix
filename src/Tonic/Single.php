<?php


namespace AbmmHasan\OOF\Tonic;


use Exception;

trait Single
{
    use Common;

    private static $instance;

    /**
     * Creates a new instance of a singleton class (via late static binding),
     * accepting a variable-length argument list.
     *
     * @param array|null $constraints
     * @return self
     * @throws Exception
     */
    final public static function instance(array $constraints = null): Single
    {
        self::__checkRequirements($constraints);

        if (!self::$instance) {
            self::$instance = new self;
        }
        return self::$instance;
    }
}
