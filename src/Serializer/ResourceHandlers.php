<?php

declare(strict_types=1);

namespace Infocyph\InterMix\Serializer;

abstract class ResourceHandlers
{
    /**
     * Register all resource handlers.
     *
     * This method iterates over all class methods prefixed with "register" and
     * invokes them. This is a convenient way to register all resource handlers
     * at once.
     */
    public static function registerDefaults(): void
    {
        foreach (get_class_methods(self::class) as $methods) {
            if ($methods !== __FUNCTION__ && str_starts_with($methods, 'register')) {
                self::$methods();
            }
        }
    }

    /**
     * Prevents instantiation of the ResourceHandlers class.
     *
     * This constructor is private to enforce static usage of the class methods,
     * ensuring that no instances of this class can be created.
     */
    private function __construct()
    {
    }
}
