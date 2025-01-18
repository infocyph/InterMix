<?php

namespace Infocyph\InterMix\Remix;

use Closure;
use Exception;
use ReflectionClass;
use ReflectionException;
use ReflectionMethod;

trait MacroMix
{
    /**
     * The registered macros for the current class.
     *
     * @var array<string, callable|object>
     */
    protected static array $macros = [];

    /**
     * Lock file handle for ensuring thread-safe operations.
     *
     * @var resource|null
     */
    private static $lockHandle = null;

    /**
     * Determines if locking is enabled.
     */
    private static function isLockEnabled(): bool
    {
        return defined('static::ENABLE_LOCK') ? static::ENABLE_LOCK : false;
    }

    /**
     * Registers macros from a structured configuration.
     *
     * @param  array<string, callable|object>  $config  An array where keys are macro names and values are callable or object definitions.
     */
    public static function loadMacrosFromConfig(array $config): void
    {
        self::acquireLock();
        try {
            foreach ($config as $name => $macro) {
                static::macro($name, $macro);
            }
        } finally {
            self::releaseLock();
        }
    }

    /**
     * Registers macros from annotations in a given class or object.
     *
     * @param  string|object  $class  The class name or object containing annotations.
     *
     * @throws ReflectionException
     */
    public static function loadMacrosFromAnnotations(string|object $class): void
    {
        self::acquireLock();
        try {
            $reflection = new ReflectionClass($class);
            foreach ($reflection->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
                $docComment = $method->getDocComment();
                if ($docComment && preg_match('/@Macro\("(\w+)"\)/', $docComment, $matches)) {
                    $macroName = $matches[1];
                    $macro = fn (...$args) => $method->invoke($class, ...$args);
                    static::macro($macroName, $macro);
                }
            }
        } finally {
            self::releaseLock();
        }
    }

    /**
     * Registers a custom macro.
     *
     * @param  string  $name  The macro name.
     * @param  callable|object  $macro  The macro definition.
     */
    public static function macro(string $name, callable|object $macro): void
    {
        self::acquireLock();
        try {
            if (is_callable($macro)) {
                $macro = static::wrapWithChaining($macro);
            }
            static::$macros[$name] = $macro;
        } finally {
            self::releaseLock();
        }
    }

    /**
     * Checks if a macro is registered.
     *
     * @param  string  $name  The macro name.
     */
    public static function hasMacro(string $name): bool
    {
        return isset(static::$macros[$name]);
    }

    /**
     * Removes a registered macro.
     *
     * @param  string  $name  The macro name.
     */
    public static function removeMacro(string $name): void
    {
        self::acquireLock();
        try {
            unset(static::$macros[$name]);
        } finally {
            self::releaseLock();
        }
    }

    /**
     * Handles static calls to the class.
     *
     * @param  string  $method  The method name.
     * @param  array  $parameters  Parameters to pass to the method.
     *
     * @throws Exception
     */
    public static function __callStatic(string $method, array $parameters): mixed
    {
        return self::process(null, $method, $parameters);
    }

    /**
     * Handles dynamic calls to the class.
     *
     * @param  string  $method  The method name.
     * @param  array  $parameters  Parameters to pass to the method.
     *
     * @throws Exception
     */
    public function __call(string $method, array $parameters): mixed
    {
        return self::process($this, $method, $parameters);
    }

    /**
     * Processes the given method call and returns the result.
     *
     * @param  object|null  $bind  The object to bind the macro to (for dynamic calls).
     * @param  string  $method  The method name.
     * @param  array  $parameters  Parameters to pass to the method.
     *
     * @throws Exception If the macro does not exist.
     */
    private static function process(?object $bind, string $method, array $parameters): mixed
    {
        if (! static::hasMacro($method)) {
            throw new Exception(
                sprintf('Method %s::%s does not exist.', static::class, $method)
            );
        }

        $macro = static::$macros[$method];

        if ($macro instanceof Closure) {
            $macro = $macro->bindTo($bind, static::class);
        }

        return $macro(...$parameters);
    }

    /**
     * Wraps a callable with method chaining support.
     *
     * @param  callable  $callable  The callable to wrap.
     */
    private static function wrapWithChaining(callable $callable): callable
    {
        return function (...$args) use ($callable) {
            $result = isset($this) && $callable instanceof Closure
                ? $callable->bindTo($this, static::class)(...$args)
                : call_user_func_array($callable, $args);

            return $result ?? $this ?? static::class;
        };
    }

    /**
     * Retrieves all registered macros.
     *
     * @return array<string, callable|object>
     */
    public static function getMacros(): array
    {
        return static::$macros;
    }

    /**
     * Acquires a lock to ensure thread-safe operations.
     */
    private static function acquireLock(): void
    {
        if (! self::isLockEnabled()) {
            return;
        }

        if (is_null(self::$lockHandle)) {
            self::$lockHandle = fopen(__FILE__, 'r');
        }

        if (self::$lockHandle !== false) {
            flock(self::$lockHandle, LOCK_EX);
        }
    }

    /**
     * Releases a lock to ensure thread-safe operations.
     */
    private static function releaseLock(): void
    {
        if (! self::isLockEnabled() || is_null(self::$lockHandle)) {
            return;
        }

        if (self::$lockHandle !== false) {
            flock(self::$lockHandle, LOCK_UN);
            fclose(self::$lockHandle);
            self::$lockHandle = null;
        }
    }

    /**
     * Mixes methods from a given object or class into the current class.
     *
     * @param  object  $mixin  The object or class containing methods to mix in.
     * @param  bool  $replace  Whether to replace existing macros with the same name.
     *
     */
    public static function mix(object $mixin, bool $replace = true): void
    {
        self::acquireLock();
        try {
            $methods = (new ReflectionClass($mixin))->getMethods(
                ReflectionMethod::IS_PUBLIC | ReflectionMethod::IS_PROTECTED
            );

            foreach ($methods as $method) {
                $name = $method->name;

                if (! $replace && static::hasMacro($name)) {
                    continue;
                }

                $macro = $method->isStatic()
                    ? fn (...$args) => $method->invoke(null, ...$args)
                    : fn (...$args) => $method->invoke($mixin, ...$args);

                static::macro($name, $macro);
            }
        } finally {
            self::releaseLock();
        }
    }
}
