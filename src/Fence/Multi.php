<?php

namespace Infocyph\InterMix\Fence;

use Exception;

trait Multi
{
    use Common;

    protected static array $instances = [];
    private ?string $instanceKey = null;

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

        static::$instances[$key] ??= new static();
        (static::$instances[$key])->instanceKey = $key;
        return static::$instances[$key];
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

    /**
     * Retrieves the instance key for the current instance.
     *
     * @return string|null The class name representing the instance key.
     */
    private function getInstanceKey(): ?string
    {
        return $this->instanceKey;
    }
}
