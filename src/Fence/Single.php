<?php

namespace Infocyph\InterMix\Fence;

use Exception;

trait Single
{
    use Common;

    protected static ?self $instance = null;

    /**
     * Creates and returns the singleton instance of the class.
     *
     * @param  array|null  $constraints  Constraints for instance creation.
     *
     * @throws Exception
     */
    final public static function instance(?array $constraints = null): static
    {
        static::checkRequirements($constraints);

        if (static::$instance === null) {
            static::$instance = new static();
        }

        return static::$instance;
    }

    /**
     * Clears the current instance.
     */
    final public static function clearInstance(): void
    {
        static::$instance = null;
    }
}
