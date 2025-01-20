<?php

namespace Infocyph\InterMix\Fence;

use Exception;

trait Multi
{
    use Common;

    protected static array $instances = [];

    /**
     * Creates or retrieves a keyed instance of the class.
     *
     * @param  string  $key  The key for the instance (default: 'default').
     * @param  array|null  $constraints  Constraints for instance creation.
     *
     * @throws Exception
     */
    final public static function instance(string $key = 'default', ?array $constraints = null): static
    {
        static::checkRequirements($constraints);

        return static::$instances[$key] ??= new static();
    }

    /**
     * Gets all active instances.
     *
     * @return array The array of active instances.
     */
    final public static function getInstances(): array
    {
        return static::$instances;
    }

    /**
     * Clears all active instances.
     */
    final public static function clearInstances(): void
    {
        static::$instances = [];
    }
}
