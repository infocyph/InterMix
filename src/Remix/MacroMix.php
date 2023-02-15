<?php

namespace AbmmHasan\OOF\Remix;

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
     * Mix another object into the class.
     *
     * @param object $mixin
     * @param bool $replace
     * @return void
     * @throws ReflectionException
     */
    public static function mix(object $mixin, bool $replace = true): void
    {
        $methods = (new ReflectionClass($mixin))->getMethods(
            ReflectionMethod::IS_PUBLIC | ReflectionMethod::IS_PROTECTED
        );

        foreach ($methods as $method) {
            if ($replace || !static::hasMacro($method->name)) {
                $method->setAccessible(true);
                static::register($method->name, $method->invoke($mixin));
            }
        }
    }

    /**
     * Checks if macro is registered.
     *
     * @param string $name
     * @return bool
     */
    public static function hasMacro(string $name): bool
    {
        return isset(static::$macros[$name]);
    }

    /**
     * Register a custom macro.
     *
     * @param string $name
     * @param callable|object $macro
     * @return void
     */
    public static function register(string $name, callable|object $macro): void
    {
        static::$macros[$name] = $macro;
    }

    /**
     * Handle static calls to the class.
     *
     * @param string $method
     * @param array $parameters
     * @return mixed
     * @throws Exception
     */
    public static function __callStatic(string $method, array $parameters)
    {
        return self::process(null, $method, $parameters);
    }

    /**
     * Process calls
     *
     * @param $bind
     * @param string $method
     * @param array $parameters
     * @return mixed
     * @throws Exception
     */
    private static function process($bind, string $method, array $parameters): mixed
    {
        if (!static::hasMacro($method)) {
            throw new Exception(
                sprintf(
                    'Method %s::%s does not exist.',
                    static::class,
                    $method
                )
            );
        }

        $macro = static::$macros[$method];

        if ($macro instanceof Closure) {
            $macro = $macro->bindTo($bind, static::class);
        }

        return $macro(...$parameters);
    }

    /**
     * Handle non-static calls to the class.
     *
     * @param string $method
     * @param array $parameters
     * @return mixed
     * @throws Exception
     */
    public function __call(string $method, array $parameters)
    {
        return self::process($this, $method, $parameters);
    }
}
