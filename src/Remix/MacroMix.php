<?php

namespace Infocyph\InterMix\Remix;

use Closure;
use Exception;
use Infocyph\InterMix\DI\Support\ReflectionResource;
use ReflectionException;
use ReflectionMethod;

trait MacroMix
{
    /**
     * @var array<string, callable|object>
     */
    protected static array $macros = [];

    /**
     * @var resource|null
     */
    private static $lockHandle = null;

    /**
     * Checks if the locking mechanism is enabled.
     *
     * Determines whether the locking feature is enabled by checking the
     * 'ENABLE_LOCK' constant in the class. If the constant is defined
     * and true, locking is enabled; otherwise, it is disabled.
     *
     * @return bool True if locking is enabled, false otherwise.
     */
    private static function isLockEnabled(): bool
    {
        return defined('static::ENABLE_LOCK') ? static::ENABLE_LOCK : false;
    }


    /**
     * Loads macros from a given configuration array.
     *
     * This method iterates over the provided configuration array, registering each
     * macro by name. It ensures thread safety by acquiring a lock before modifying
     * the shared state and releasing the lock afterward.
     *
     * @param array<string, callable> $config An associative array where keys are
     *        macro names and values are callable macros.
     *
     * @return void
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
     * Loads macros from a class based on annotations.
     *
     * This method searches for PHPDoc annotations in the form of `@Macro("<name>")`
     * on public methods of the given class. For each found annotation, it registers a
     * macro with the given name, pointing to the corresponding method.
     *
     * @param string|object $class The class to load macros from. Can be a class name
     *        or an instance of the class.
     * @throws ReflectionException
     */
    public static function loadMacrosFromAnnotations(string|object $class): void
    {
        $reflection = ReflectionResource::getClassReflection($class);
        foreach ($reflection->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
            $docComment = $method->getDocComment();
            if ($docComment && preg_match('/@Macro\("(\w+)"\)/', $docComment, $matches)) {
                $macroName = $matches[1];
                $macro = fn (...$args) => $method->invoke($class, ...$args);
                static::macro($macroName, $macro);
            }
        }
    }


    /**
     * Registers a macro.
     *
     * Registers a macro with the given name. If the macro is a callable, it will be
     * wrapped with the chaining mechanism. If the macro is an object, it will be
     * stored directly.
     *
     * @param string $name The macro name.
     * @param callable|object $macro The macro to register.
     *
     * @return void
     */
    public static function macro(string $name, callable|object $macro): void
    {
        if (is_callable($macro)) {
            $macro = static::wrapWithChaining($macro);
        }
        static::$macros[$name] = $macro;
    }


    /**
     * Checks if a macro is registered.
     *
     * Determines if a macro with the specified name exists in the
     * registered macros array.
     *
     * @param string $name The name of the macro to check.
     * @return bool True if the macro is registered, false otherwise.
     */
    public static function hasMacro(string $name): bool
    {
        return isset(static::$macros[$name]);
    }


    /**
     * Removes a macro.
     *
     * Removes a macro with the specified name from the registered macros array.
     *
     * @param string $name The name of the macro to remove.
     *
     * @return void
     */
    public static function removeMacro(string $name): void
    {
        unset(static::$macros[$name]);
    }


    /**
     * Handles static calls to the class.
     *
     * This method processes calls to class methods that do not exist and
     * delegates the call to the registered macro if it exists.
     *
     * @param string $method The method name.
     * @param array $parameters Parameters to pass to the method.
     *
     * @return mixed The result of the macro call.
     *
     * @throws Exception If the macro does not exist.
     */
    public static function __callStatic(string $method, array $parameters): mixed
    {
        return self::process(null, $method, $parameters);
    }


    /**
     * Handles dynamic calls to the object.
     *
     * This method processes calls to object methods that do not exist and
     * delegates the call to the registered macro if it exists.
     *
     * @param string $method The method name.
     * @param array $parameters Parameters to pass to the method.
     *
     * @return mixed The result of the macro call.
     *
     * @throws Exception If the macro does not exist.
     */
    public function __call(string $method, array $parameters): mixed
    {
        return self::process($this, $method, $parameters);
    }


    /**
     * Process a macro call.
     *
     * Process a call to a macro on the class or object. If the macro does not
     * exist, an exception is thrown.
     *
     * @param object|null $bind The object to bind the macro call to, or null
     *     for static calls.
     * @param string $method The method name to call.
     * @param array $parameters Parameters to pass to the macro.
     *
     * @return mixed The result of the macro call.
     *
     * @throws Exception If the macro does not exist.
     */
    private static function process(?object $bind, string $method, array $parameters): mixed
    {
        if (!static::hasMacro($method)) {
            throw new Exception(
                sprintf('Method %s::%s does not exist.', static::class, $method),
            );
        }

        $macro = static::$macros[$method];

        if ($macro instanceof Closure) {
            $macro = $macro->bindTo($bind, static::class);
        }

        return $macro(...$parameters);
    }


    /**
     * Wraps a callable to chain method calls.
     *
     * If the callable is a closure, it is bound to the current object (if
     * available) and called with the given arguments. If the callable is not a
     * closure, it is called directly with the given arguments.
     *
     * If the result of the callable is not set, the method returns the current
     * object (if available) or the class name (if not available).
     *
     * @param callable $callable The callable to wrap.
     *
     * @return callable The wrapped callable.
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
     * Returns all registered macros.
     *
     * Retrieves a list of all macros currently registered with the class.
     *
     * @return array<string, callable|object> An array of all registered macros.
     */
    public static function getMacros(): array
    {
        return static::$macros;
    }


    /**
     * Acquires a lock to ensure thread-safe operations.
     *
     * This method checks if locking is enabled and acquires an exclusive lock
     * on the current file. It initializes the lock handle if it is not already set.
     * If the lock handle is valid, it uses `flock` to apply an exclusive lock.
     *
     * @return void
     */
    private static function acquireLock(): void
    {
        if (!self::isLockEnabled()) {
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
     * Releases the lock to allow other processes to access the resource.
     *
     * This method checks if locking is enabled and releases the exclusive lock
     * on the current file by using `flock` to remove the lock. It then closes
     * the lock handle and sets it to null to indicate that the lock is no longer
     * held. If locking is not enabled or the lock handle is not set, the method
     * returns without taking any action.
     *
     * @return void
     */
    private static function releaseLock(): void
    {
        if (!self::isLockEnabled() || is_null(self::$lockHandle)) {
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
     * This method takes an object or class name as the first argument and an optional
     * boolean flag for whether to replace existing macros with the same names.
     * It then iterates over all public and protected methods of the given object
     * or class and registers each as a macro with the same name.
     *
     * @param object|string $mixin The object or class to mix methods from.
     * @param bool $replace Whether to replace existing macros with the same names.
     *
     * @return void
     * @throws ReflectionException
     */
    public static function mix(object|string $mixin, bool $replace = true): void
    {
        $instance = is_object($mixin) ? $mixin : new $mixin();
        $methods = (ReflectionResource::getClassReflection($instance))->getMethods(
            ReflectionMethod::IS_PUBLIC | ReflectionMethod::IS_PROTECTED,
        );

        foreach ($methods as $method) {
            $name = $method->name;

            if (!$replace && static::hasMacro($name)) {
                continue;
            }

            $macro = $method->isStatic()
                ? fn (...$args) => $method->invoke(null, ...$args)
                : fn (...$args) => $method->invoke($instance, ...$args);

            static::macro($name, $macro);
        }
    }
}
