<?php

declare(strict_types=1);

namespace Infocyph\InterMix\DI;

use Closure;
use Infocyph\InterMix\Exceptions\ContainerException;
use Infocyph\InterMix\Serializer\ValueSerializer;
use InvalidArgumentException;
use Random\RandomException;
use ReflectionException;

final readonly class Invoker
{
    /**
     * Construct an instance of the invoker.
     *
     * @param Container $container The container to use for resolving callables.
     */
    private function __construct(private Container $container)
    {
    }

    /**
     * Create an instance of the invoker with a specified container.
     *
     * @param Container $container The container to use for resolving callables.
     *
     * @return static An instance of the invoker.
     */
    public static function with(Container $container): self
    {
        return new self($container);
    }

    /**
     * Retrieve a shared instance of the invoker.
     *
     * This method returns a singleton instance of the invoker, using
     * a container instance associated with the alias 'intermix'.
     *
     * @return self The shared instance of the invoker.
     * @throws ContainerException
     */
    public static function shared(): self
    {
        static $inst;
        return $inst ??= new self(Container::instance(__DIR__));
    }

    /**
     * Get the container instance used by the invoker.
     *
     * @return Container The container instance associated with the invoker.
     */
    public function getContainer(): Container
    {
        return $this->container;
    }

    /**
     * Execute a callable with optional parameters.
     *
     * This method is a convenience wrapper for the container's `call` method.
     * It resolves the callable and executes it with optional parameters.
     * If the callable is a class with a method, the method is invoked.
     * If the callable is a closure or a plain string/object, it is executed directly.
     *
     * @param array|string|object $target The callable to be executed.
     * @param array $args Optional parameters to pass to the callable.
     *
     * @return mixed The result of executing the callable.
     *
     * @throws ContainerException If the callable cannot be resolved.
     * @throws ReflectionException If the callable is a class without a default constructor.
     * @throws InvalidArgumentException|RandomException If the callable is not a valid callable.
     */
    public function invoke(array|string|object $target, array $args = []): mixed
    {
        // Serialized closure fast-path
        if (is_string($target) && ValueSerializer::isSerializedClosure($target)) {
            return $this->routeCallable($target, $args);
        }

        $desc = $this->container->parseCallable($target);

        return match ($desc['kind']) {
            // Closure / invokable / callable array → just call
            'closure' => $this->routeCallable($desc['closure'], $args),

            // Global function name → just call
            'function' => $this->routeCallable($desc['function'], $args),

            // Class only → register ctor args then resolve
            'class' => $this->container
                ->registration()->registerClass($desc['class'], $args)
                ->invocation()->getReturn($desc['class']),

            // Class + method → register method args then resolve
            'method' => $this->container
                ->registration()->registerMethod($desc['class'], $desc['method'], $args)
                ->invocation()->getReturn($desc['class']),
        };
    }

    /**
     * Resolve a value associated with a given ID from the container.
     *
     * @param string $id The ID of the value to retrieve.
     *
     * @return mixed The resolved value or the cached value if available.
     *
     * @throws InvalidArgumentException If the value cannot be resolved.
     */
    public function resolve(string $id): mixed
    {
        return $this->container->get($id);
    }

    /**
     * Create a new instance of a class, optionally invoking a method.
     *
     * This is a convenience wrapper for the container's `make` method.
     *
     * The constructor parameters are passed as an array, and the same applies
     * for the method parameters. If no method is specified, the constructed
     * instance will be returned.
     *
     * @param string $class The fully-qualified class name to create an instance of.
     * @param array $ctorArgs An array of constructor parameters.
     * @param string|bool $method The name of the method to invoke, or false to not invoke a method.
     * @param array $methodArgs An array of method parameters.
     *
     * @throws ContainerException|ReflectionException
     */
    public function make(
        string $class,
        array $ctorArgs = [],
        string|bool $method = false,
        array $methodArgs = [],
    ): mixed {
        $this->container->registration()->registerClass($class, $ctorArgs);

        if ($method === false) {
            return $this->container->make($class, false);
        }

        $this->container->registration()->registerMethod($class, $method, $methodArgs);
        return $this->container->make($class, $method);
    }


    /**
     * Returns a callable for the given target, caching the result.
     *
     * This method takes a target, which can be a string representing a class name
     * or an object instance. It ensures the target is invokable, either by creating
     * an instance through dependency injection if a class name is provided, or by
     * using the provided object directly. The resulting callable is cached to
     * optimize subsequent calls.
     *
     * @param string|object $target The class name or object to convert to a callable.
     *
     * @return callable The callable representation of the target.
     *
     * @throws InvalidArgumentException If the target is not invokable.
     */
    public function callableFor(string|object $target): callable
    {
        static $cache = [];
        $key = \is_string($target) ? $target : $target::class;
        return $cache[$key] ??= (function () use ($target) {
            // ① get an *instance* (DI still runs only once)
            $instance = \is_string($target)
                ? $this->make($target)     // ctor-injected object
                : $target;                 // already an object

            if (!\is_callable($instance)) {
                throw new InvalidArgumentException(
                    sprintf('%s is not invokable.', $key = $instance::class),
                );
            }

            // ② turn “object with __invoke” into a bound Closure (PHP 8+)
            return $instance(...);        // promoted callable
        })();
    }

    /**
     * Serializes a given value into a string.
     *
     * This method wraps the ValueSerializer serialize function.
     *
     * @param mixed $v The value to be serialized, which may contain resources.
     *
     * @return string The serialized string representation of the value.
     *
     * @throws InvalidArgumentException If a resource type has no registered handler.
     */
    public function serialize(mixed $v): string
    {
        return ValueSerializer::serialize($v);
    }

    /**
     * Unserializes a string into its original value.
     *
     * This method wraps the Opis Closure unserialize function and unwraps any
     * wrapped resources within the resulting value using registered resource
     * handlers.
     *
     * @param string $b The serialized string to be converted back to its original form.
     *
     * @return mixed The original value, with any resources restored.
     */
    public function unserialize(string $b): mixed
    {
        return ValueSerializer::unserialize($b);
    }

    /**
     * Routes a callable to the appropriate execution path based on its type.
     *
     * This method handles various types of callables including closures, function strings,
     * class strings, and invokable objects. It determines the appropriate execution path
     * by checking the type and properties of the given callable. If the callable is a closure,
     * it is executed directly. For function strings and invokable objects, it uses the
     * `viaClosure` method to execute them. If the callable is a class string, an instance
     * is created with optional `__invoke` execution. Additionally, serialized closures
     * are detected and unserialized for execution.
     *
     * @param mixed $callable The callable to be routed and executed.
     * @param array $args The arguments to pass to the callable.
     * @return mixed The result of executing the callable.
     * @throws ContainerException|RandomException|ReflectionException|\Psr\Cache\InvalidArgumentException
     */
    private function routeCallable(mixed $callable, array $args): mixed
    {
        return match (true) {
            \is_string($callable) => match (true) {
                ValueSerializer::isSerializedClosure($callable) => $this->viaClosure(ValueSerializer::unserialize($callable), $args, ),
                !\str_contains($callable, '::') && \function_exists($callable) => $this->viaClosure($callable(...), $args),
                \str_contains($callable, '::') && \is_callable($callable) => $this->viaClosure($callable(...), $args),
                \class_exists($callable) => $this->container->make($callable),
                default => throw new \InvalidArgumentException('Unsupported callable formation.'),
            },
            $callable instanceof \Closure => $this->viaClosure($callable, $args),
            \is_object($callable) && \is_callable($callable) => $this->viaClosure($callable(...), $args),
            default => throw new \InvalidArgumentException('Unsupported callable formation.'),
        };
    }

    /**
     * Invoke a closure with the given arguments. This is a convenience method
     * that registers the closure with the container and retrieves the result.
     *
     * @param Closure $fn The closure to invoke.
     * @param array $args The arguments to pass to the closure.
     * @return mixed The result of the closure invocation.
     * @throws ContainerException|ReflectionException|\Psr\Cache\InvalidArgumentException|RandomException
     */
    private function viaClosure(Closure $fn, array $args): mixed
    {
        static $i = 0;
        $alias = 'λ' . ($i++);
        return $this->container
            ->registration()->registerClosure($alias, $fn, $args)
            ->invocation()->getReturn($alias);
    }
}
