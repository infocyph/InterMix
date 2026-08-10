<?php

declare(strict_types=1);

namespace Infocyph\InterMix\DI;

use Closure;
use Infocyph\InterMix\Exceptions\ContainerException;
use Infocyph\InterMix\Serializer\ClosureSerializer;
use InvalidArgumentException;
use ReflectionException;

final class Invoker
{
    private const int CALLABLE_CACHE_LIMIT = 256;

    private static ?self $sharedInstance = null;

    /** @var array<string, callable(): mixed> */
    private array $callableCache = [];

    /**
     * Construct an instance of the invoker.
     *
     * @param Container $container The container to use for resolving callables.
     */
    private function __construct(private readonly Container $container) {}

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
        if (!self::$sharedInstance instanceof self) {
            self::$sharedInstance = new self(Container::instance(Container::DI_ALIAS));
        }

        return self::$sharedInstance;
    }

    /**
     * Create an instance of the invoker with a specified container.
     *
     * @param Container $container The container to use for resolving callables.
     *
     * @return self An instance of the invoker.
     */
    public static function with(Container $container): self
    {
        return new self($container);
    }

    /**
     * Returns a callable for the given target, caching the result.
     *
     * This method takes a target, which can be a string representing a class name
     * or an object instance. It ensures the target is invokable, either by creating
     * an instance through dependency injection if a class name is provided, or by
     * using the provided object directly. The resulting callable is cached to
     * speed up subsequent calls.
     *
     * @param string|object $target The class name or object to convert to a callable.
     *
     * @return callable The callable representation of the target.
     *
     * @throws InvalidArgumentException If the target is not invokable.
     */
    public function callableFor(string|object $target): callable
    {
        if (\is_object($target)) {
            if (!\is_callable($target)) {
                throw new InvalidArgumentException(
                    sprintf('%s is not invokable.', $target::class),
                );
            }

            return $target(...);
        }

        if (isset($this->callableCache[$target])) {
            return $this->callableCache[$target];
        }

        $instance = $this->make($target);
        if (!\is_object($instance) || !\is_callable($instance)) {
            throw new InvalidArgumentException(
                sprintf(
                    '%s is not invokable.',
                    $target,
                ),
            );
        }

        if (count($this->callableCache) >= self::CALLABLE_CACHE_LIMIT) {
            unset($this->callableCache[array_key_first($this->callableCache)]);
        }

        return $this->callableCache[$target] = $instance(...);
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
     * @param string|array{0: string|object, 1: string}|callable $target The callable to be executed.
     * @param array<int|string, mixed> $args Optional parameters to pass to the callable.
     *
     * @return mixed The result of executing the callable.
     *
     * @throws ContainerException If the callable cannot be resolved.
     * @throws ReflectionException If the callable is a class without a default constructor.
     * @throws InvalidArgumentException If the callable is not a valid callable.
     */
    public function invoke(string|array|callable $target, array $args = []): mixed
    {
        if ($target instanceof Closure) {
            return $this->viaClosure($target, $args);
        }

        if (is_object($target)) {
            return $this->viaClosure(Closure::fromCallable($target), $args);
        }

        if (is_array($target)) {
            return $this->invokeArray($target, $args);
        }

        if (is_string($target)) {
            return $this->invokeString($target, $args);
        }

        throw new InvalidArgumentException('Unsupported callable formation.');
    }

    /**
     * Invoke a closure directly through the active resolver without registering aliases.
     *
     * @param array<int|string, mixed> $args
     * @throws ContainerException|ReflectionException|\Psr\Cache\InvalidArgumentException
     */
    public function invokeClosure(Closure $closure, array $args = []): mixed
    {
        return $this->container
            ->getCurrentResolver()
            ->closureSettler($closure, $args);
    }

    /**
     * Create a new instance of a class, optionally invoking a method.
     *
     * This is a convenience wrapper for the container's `make` method.
     *
     * Constructor and optional method arguments are supplied independently.
     *
     * @param string $class The fully-qualified class name to create an instance of.
     * @param array<int|string, mixed> $ctorArgs An array of constructor parameters.
     * @param string|bool $method The name of the method to invoke, or false to not invoke a method.
     * @param array<int|string, mixed> $methodArgs An array of method parameters.
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

        if ($method === true) {
            return $this->container->make($class, true);
        }

        $this->container->registration()->registerMethod($class, $method, $methodArgs);

        return $this->container->make($class, $method);
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
     * @param array{0: string|object, 1: string} $target
     * @param array<int|string, mixed> $args
     */
    private function invokeArray(array $target, array $args): mixed
    {
        if (is_callable($target)) {
            return $this->container
                ->getCurrentResolver()
                ->closureSettler($target, $args);
        }

        $class = $target[0];
        $method = $target[1];
        if (is_string($class) && class_exists($class) && method_exists($class, $method)) {
            return $this->container
                ->registration()->registerMethod($class, $method, $args)
                ->invocation()->getReturn($class);
        }

        throw new InvalidArgumentException('Unsupported callable formation.');
    }

    /**
     * @param array<int|string, mixed> $args
     */
    private function invokeString(string $target, array $args): mixed
    {

        $classMethod = str_contains($target, '::');
        if (!$classMethod && function_exists($target)) {
            return $this->viaClosure(Closure::fromCallable($target), $args);
        }

        if ($classMethod && is_callable($target)) {
            return $this->viaClosure(Closure::fromCallable($target), $args);
        }

        if (class_exists($target)) {
            return $this->make($target, $args);
        }

        if (ClosureSerializer::isSerialized($target)) {
            return $this->viaClosure(ClosureSerializer::unserialize($target), $args);
        }

        throw new InvalidArgumentException('Unsupported callable formation.');
    }

    /**
     * Invoke a closure with the given arguments. This is a convenience method
     * that registers the closure with the container and retrieves the result.
     *
     * @param Closure $fn The closure to invoke.
     * @param array<int|string, mixed> $args The arguments to pass to the closure.
     * @return mixed The result of the closure invocation.
     * @throws ContainerException|ReflectionException|\Psr\Cache\InvalidArgumentException
     */
    private function viaClosure(Closure $fn, array $args): mixed
    {
        return $this->invokeClosure($fn, $args);
    }
}
