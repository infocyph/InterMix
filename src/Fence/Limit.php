<?php

namespace Infocyph\InterMix\Fence;

use Exception;

trait Limit
{
    use Common;
    protected static array $instances = [];
    protected static int $limit = 2;
    private ?string $instanceKey = null;

    /**
     * Creates or retrieves an instance of the class.
     *
     * @param  string  $key  The key for the instance. Default is 'default'.
     * @param  array|null  $constraints  Constraints for instance creation.
     *
     * @throws Exception If the initialization limit is exceeded.
     */
    final public static function instance(string $key = 'default', ?array $constraints = null): static
    {
        static::checkRequirements($constraints);

        if (($count = count(static::$instances)) >= static::$limit) {
            throw new Exception('Instance creation failed: Initialization limit ('.$count.' of '.static::$limit.' for '.static::class.') exceeded.');
        }

        static::$instances[$key] ??= new static();
        (static::$instances[$key])->instanceKey = $key;
        return static::$instances[$key];
    }

    /**
     * Sets a new initialization limit.
     *
     * @param  int  $number  The new limit.
     *
     * @throws Exception
     */
    final public static function setLimit(int $number): void
    {
        if ($number < 1) {
            throw new Exception('Limit must be at least 1.');
        }
        static::$limit = $number;
    }

    /**
     * Retrieves the instance key for the current instance.
     *
     * @return string The class name representing the instance key.
     */
    private function getInstanceKey(): string
    {
        return $this->instanceKey;
    }
}
