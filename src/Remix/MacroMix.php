<?php

namespace AbmmHasan\InterMix\Remix;

use Closure;
use Exception;
use ReflectionClass;
use ReflectionException;
use ReflectionMethod;

trait MacroMix
{
    /**
     * The registered string macros.
     *
     * @var array
     */
    protected static array $macros = [];

    /**
     * Mixes the methods from a given object into the current class.
     *
     * @param object $mixin The object containing the methods to be mixed in.
     * @param bool $replace Whether to replace existing methods with the same name.
     * @return void
     * @throws ReflectionException If there is an error accessing the reflection class.
     */
    public static function mix(object $mixin, bool $replace = true): void
    {
        $methods = (new ReflectionClass($mixin))->getMethods(
            ReflectionMethod::IS_PUBLIC | ReflectionMethod::IS_PROTECTED
        );

        foreach ($methods as $method) {
            if ($replace || !static::hasMacro($method->name)) {
                $method->setAccessible(true);
                static::macro($method->name, $method->invoke($mixin));
            }
        }
    }

    /**
     * Check if a macro is registered.
     *
     * @param string $name The name of the macro.
     * @return bool Returns true if the macro is registered, false otherwise.
     */
    public static function hasMacro(string $name): bool
    {
        return isset(static::$macros[$name]);
    }

    /**
     * Register a custom macro.
     *
     * @param string $name The name of the macro.
     * @param callable|object $macro The macro to be added.
     * @return void
     */
    public static function macro(string $name, callable|object $macro): void
    {
        static::$macros[$name] = $macro;
    }

    /**
     * Handle static calls to the class.
     *
     * @param string $method The method name to be called.
     * @param array $parameters An array of parameters to be passed to the method.
     * @return mixed The result of the method call.
     * @throws Exception
     */
    public static function __callStatic(string $method, array $parameters)
    {
        return self::process(null, $method, $parameters);
    }

    /**
     * Handle dynamic calls to the class.
     *
     * @param string $method The name of the method being called.
     * @param array $parameters The parameters passed to the method.
     * @return mixed The return value of the called method.
     * @throws Exception
     */
    public function __call(string $method, array $parameters)
    {
        return self::process($this, $method, $parameters);
    }

    /**
     * Processes the given bind, method and parameters and returns the result.
     *
     * @param object|null $bind The bind object to be used in the method call.
     * @param string $method The name of the method to be called.
     * @param array $parameters The parameters to be passed to the method.
     * @return mixed The result of the method call.
     * @throws Exception If the method does not exist.
     */
    private static function process(object|null $bind, string $method, array $parameters): mixed
    {
        if (!static::hasMacro($method)) {
            throw new Exception(
                'Method' . static::class . "::$method does not exist."
            );
        }

        $macro = static::$macros[$method];

        if ($macro instanceof Closure) {
            $macro = $macro->bindTo($bind, static::class);
        }

        return $macro(...$parameters);
    }
}
