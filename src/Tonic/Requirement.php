<?php


namespace AbmmHasan\OOF\Tonic;


use Exception;

trait Requirement
{

    use Common;

    private static $instance;

    /**
     * Creates a new instance of a singleton class if the
     * requirements are fulfilled
     *
     * @param array|null $constraints
     * @return self
     * @throws Exception
     */
    final public static function instance(array $constraints = null): static
    {
        self::__checkRequirements($constraints);

        if (!self::$instance) {
            self::$instance = new self;
        }

        return self::$instance;
    }
}
