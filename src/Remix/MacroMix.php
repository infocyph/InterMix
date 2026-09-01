<?php

declare(strict_types=1);

namespace Infocyph\InterMix\Remix;

use BadMethodCallException;
use Closure;
use Infocyph\InterMix\Internal\ReflectionResource;
use InvalidArgumentException;
use ReflectionException;
use ReflectionFunction;
use ReflectionMethod;
use RuntimeException;

// Public trait consumers live in downstream projects and the excluded test suite.
// @phpstan-ignore trait.unused
trait MacroMix
{
    /** @var array<string, bool> */
    protected static array $macroIsBindableClosure = [];

    /** @var array<string, bool> */
    protected static array $macroIsStaticClosure = [];

    /**
     * @var array<string, callable>
     */
    protected static array $macros = [];

    /**
     * Handles dynamic calls to the object.
     *
     * This method processes calls to object methods that do not exist and
     * delegates the call to the registered macro if it exists.
     *
     * @param string $method The method name.
     * @param array<int, mixed> $parameters Parameters to pass to the method.
     *
     * @return mixed The result of the macro call.
     *
     * @throws BadMethodCallException If the macro does not exist.
     */
    public function __call(string $method, array $parameters): mixed
    {
        return self::process($this, $method, $parameters);
    }

    /**
     * Handles static calls to the class.
     *
     * This method processes calls to class methods that do not exist and
     * delegates the call to the registered macro if it exists.
     *
     * @param string $method The method name.
     * @param array<int, mixed> $parameters Parameters to pass to the method.
     *
     * @return mixed The result of the macro call.
     *
     * @throws BadMethodCallException If the macro does not exist.
     */
    public static function __callStatic(string $method, array $parameters): mixed
    {
        return self::process(null, $method, $parameters);
    }

    /**
     * Returns all registered macros.
     *
     * Retrieves a list of all macros currently registered with the class.
     *
     * @return array<string, callable> An array of all registered macros.
     */
    public static function getMacros(): array
    {
        return static::$macros;
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
     * Loads macros from a class based on annotations.
     *
     * This method searches for PHPDoc annotations in the form of `@Macro("<name>")`
     * on public methods of the given class. For each found annotation, it registers a
     * macro with the given name, pointing to the corresponding method.
     *
     * @param string|object $class The class to load macros from. Can be a class name
     *                             or an instance of the class.
     * @throws ReflectionException
     */
    public static function loadMacrosFromAnnotations(string|object $class): void
    {
        $instance = is_object($class) ? $class : null;
        $reflection = ReflectionResource::getClassReflection($class);
        $macros = [];
        foreach ($reflection->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
            $docComment = $method->getDocComment();
            if ($docComment && preg_match('/@Macro\("(\w+)"\)/', $docComment, $matches)) {
                $macroName = $matches[1];
                if (!$method->isStatic() && $instance === null) {
                    throw new InvalidArgumentException(
                        "An object is required to load instance macro {$reflection->getName()}::{$method->getName()}().",
                    );
                }
                $macros[$macroName] = $method->isStatic()
                    ? static fn(...$args) => $method->invoke(null, ...$args)
                    : static fn(...$args) => $method->invoke($instance, ...$args);
            }
        }

        static::registerMany($macros);
    }

    /**
     * Loads macros from a given configuration array.
     *
     * This method iterates over the provided configuration array, registering each
     * macro by name. It ensures thread safety by acquiring a lock before modifying
     * the shared state and releasing the lock afterward.
     *
     * @param array<string, callable> $config An associative array where keys are
     *                                        macro names and values are callable macros.
     */
    public static function loadMacrosFromConfig(array $config): void
    {
        static::registerMany($config);
    }

    /**
     * Registers a macro.
     *
     * Registers a macro with the given name.
     *
     * @param string $name The macro name.
     * @param callable $macro The macro to register.
     */
    public static function macro(string $name, callable $macro): void
    {
        if (!self::isLockEnabled()) {
            static::registerMacroUnlocked($name, $macro);

            return;
        }

        self::mutate(static fn() => static::registerMacroUnlocked($name, $macro));
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
     * @throws ReflectionException
     */
    public static function mix(object|string $mixin, bool $replace = true): void
    {
        $instance = is_object($mixin) ? $mixin : null;
        $reflection = ReflectionResource::getClassReflection($mixin);
        $methods = $reflection->getMethods(
            ReflectionMethod::IS_PUBLIC | ReflectionMethod::IS_PROTECTED,
        );

        $macros = [];
        foreach ($methods as $method) {
            if ($method->isConstructor() || $method->isDestructor()) {
                continue;
            }
            if (!$method->isStatic() && $instance === null) {
                throw new InvalidArgumentException(
                    "An object is required to mix instance method {$reflection->getName()}::{$method->getName()}().",
                );
            }
            $name = $method->name;

            $macros[$name] = $method->isStatic()
                ? static fn(...$args) => $method->invoke(null, ...$args)
                : static fn(...$args) => $method->invoke($instance, ...$args);
        }

        if (!self::isLockEnabled()) {
            static::registerMixinUnlocked($macros, $replace);

            return;
        }

        self::mutate(static fn() => static::registerMixinUnlocked($macros, $replace));
    }

    /**
     * Removes a macro.
     *
     * Removes a macro with the specified name from the registered macros array.
     *
     * @param string $name The name of the macro to remove.
     */
    public static function removeMacro(string $name): void
    {
        if (!self::isLockEnabled()) {
            static::removeMacroUnlocked($name);

            return;
        }

        self::mutate(static fn() => static::removeMacroUnlocked($name));
    }

    private static function isLockEnabled(): bool
    {
        $class = static::class;

        return defined("$class::ENABLE_LOCK") && (bool) constant("$class::ENABLE_LOCK");
    }

    private static function mutate(Closure $operation): mixed
    {
        $directory = sys_get_temp_dir() . '/intermix-locks';
        if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) {
            throw new RuntimeException('Unable to create the InterMix lock directory.');
        }
        chmod($directory, 0700);
        $path = $directory . '/macro-' . hash('xxh128', static::class) . '.lock';
        $handle = fopen($path, 'c');
        if ($handle === false) {
            throw new RuntimeException('Unable to open the MacroMix mutation lock.');
        }
        if (!flock($handle, LOCK_EX)) {
            fclose($handle);

            throw new RuntimeException('Unable to acquire the MacroMix mutation lock.');
        }

        try {
            return $operation();
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    /**
     * Process a macro call.
     *
     * Process a call to a macro on the class or object. If the macro does not
     * exist, an exception is thrown.
     *
     * @param object|null $bind The object to bind the macro call to, or null
     *                          for static calls.
     * @param string $method The method name to call.
     * @param array<int, mixed> $parameters Parameters to pass to the macro.
     *
     * @return mixed The result of the macro call.
     *
     * @throws BadMethodCallException If the macro does not exist.
     */
    private static function process(?object $bind, string $method, array $parameters): mixed
    {
        if (!static::hasMacro($method)) {
            throw new BadMethodCallException(
                sprintf('Method %s::%s does not exist.', static::class, $method),
            );
        }

        $macro = static::$macros[$method];

        if ($macro instanceof Closure) {
            $result = null;
            $closure = $macro;

            $isStaticClosure = static::$macroIsStaticClosure[$method]
                ??= new ReflectionFunction($macro)->isStatic();

            if ($bind === null && !$isStaticClosure) {
                throw new BadMethodCallException(
                    sprintf('Cannot call non-static macro %s::%s() statically.', static::class, $method),
                );
            }
            if ($bind !== null && !$isStaticClosure) {
                if (!(static::$macroIsBindableClosure[$method] ?? false)) {
                    throw new BadMethodCallException(
                        sprintf('Unable to bind macro %s::%s().', static::class, $method),
                    );
                }
                $bound = $macro->bindTo($bind, static::class);
                if (!$bound instanceof Closure) {
                    throw new BadMethodCallException(
                        sprintf('Unable to bind macro %s::%s().', static::class, $method),
                    );
                }
                $closure = $bound;
            }

            $result = $closure(...$parameters);

            return $result ?? $bind ?? static::class;
        }

        if (!is_callable($macro)) {
            throw new BadMethodCallException(
                sprintf('Method %s::%s is not callable.', static::class, $method),
            );
        }

        $result = $macro(...$parameters);

        return $result ?? $bind ?? static::class;
    }

    private static function registerMacroUnlocked(string $name, callable $macro): void
    {
        static::$macros[$name] = $macro;
        if ($macro instanceof Closure) {
            $reflection = new ReflectionFunction($macro);
            static::$macroIsStaticClosure[$name] = $reflection->isStatic();
            static::$macroIsBindableClosure[$name] = str_starts_with($reflection->getName(), '{closure');

            return;
        }

        unset(static::$macroIsBindableClosure[$name], static::$macroIsStaticClosure[$name]);
    }

    /**
     * @param array<string, callable> $macros
     */
    private static function registerMany(array $macros): void
    {
        if (!self::isLockEnabled()) {
            foreach ($macros as $name => $macro) {
                static::registerMacroUnlocked($name, $macro);
            }

            return;
        }

        self::mutate(static function () use ($macros): void {
            foreach ($macros as $name => $macro) {
                static::registerMacroUnlocked($name, $macro);
            }
        });
    }

    /**
     * @param array<string, callable> $macros
     * @param bool $replace Whether existing macro names may be replaced.
     */
    private static function registerMixinUnlocked(array $macros, bool $replace): void
    {
        foreach ($macros as $name => $macro) {
            if (!$replace && isset(static::$macros[$name])) {
                continue;
            }

            static::registerMacroUnlocked($name, $macro);
        }
    }

    private static function removeMacroUnlocked(string $name): void
    {
        unset(static::$macros[$name], static::$macroIsBindableClosure[$name], static::$macroIsStaticClosure[$name]);
    }
}
