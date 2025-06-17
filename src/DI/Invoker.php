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
        return $inst ??= new self(Container::instance('intermix'));
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
        [$callableOrClass, $method] = $this->container->parseCallable($target);

        /* (a) Closure or plain callable string / object -------------------- */
        if ($method === null) {
            return $this->routeCallable($callableOrClass, $args);
        }

        /* (b)  [Class, method] resolved by parseCallable ------------------- */
        return $this->container
            ->registration()->registerMethod($callableOrClass, $method, $args)
            ->invocation()->getReturn($callableOrClass);
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

    /* ---------- internals -------------------------------------------------- */

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
        // parseCallable guarantees: Closure | function-string | class-string
        if ($callable instanceof Closure) {
            return $this->viaClosure($callable, $args);
        }

        // Callable function string
        if (is_string($callable) && is_callable($callable, false, $fnName)) {
            return $this->viaClosure($callable(...), $args);
        }

        // Invokable object
        if (is_object($callable) && is_callable($callable)) {
            return $this->viaClosure($callable(...), $args);
        }

        // Fallback: treat as class name with optional __invoke
        if (is_string($callable) && class_exists($callable)) {
            return $this->container->make($callable);
        }

        // Serialized closure payload?
        if (is_string($callable) && ValueSerializer::isSerializedClosure($callable)) {
            $closure = ValueSerializer::unserialize($callable);
            return $this->viaClosure($closure, $args);
        }

        throw new InvalidArgumentException('Unsupported callable formation.');
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
        $alias = 'λ' . bin2hex(random_bytes(4));
        return $this->container
            ->registration()->registerClosure($alias, $fn, $args)
            ->invocation()->getReturn($alias);
    }
}
