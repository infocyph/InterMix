<?php

namespace AbmmHasan\OOF\Remix;

use Exception;
use Closure;
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
     * Register a custom macro.
     *
     * @param string $name
     * @param callable|object $macro
     * @return void
     */
    public static function __register(string $name, callable|object $macro)
    {
        static::$macros[$name] = $macro;
    }

    /**
     * Mix another object into the class.
     *
     * @param object $mixin
     * @param bool $replace
     * @return void
     * @throws ReflectionException
     */
    public static function __mix(object $mixin, bool $replace = true)
    {
        $methods = (new ReflectionClass($mixin))->getMethods(
            ReflectionMethod::IS_PUBLIC | ReflectionMethod::IS_PROTECTED
        );

        foreach ($methods as $method) {
            if ($replace || !static::__hasMacro($method->name)) {
                $method->setAccessible(true);
                static::__register($method->name, $method->invoke($mixin));
            }
        }
    }

    /**
     * Checks if macro is registered.
     *
     * @param string $name
     * @return bool
     */
    public static function __hasMacro(string $name): bool
    {
        return isset(static::$macros[$name]);
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
        return self::__process(null, $method, $parameters);
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
        return self::__process($this, $method, $parameters);
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
    private static function __process($bind, string $method, array $parameters): mixed
    {
        if (!static::__hasMacro($method)) {
            throw new Exception(sprintf(
                'Method %s::%s does not exist.', static::class, $method
            ));
        }

        $macro = static::$macros[$method];

        if ($macro instanceof Closure) {
            $macro = $macro->bindTo($bind, static::class);
        }

        return $macro(...$parameters);
    }
}
