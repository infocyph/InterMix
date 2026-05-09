<?php

declare(strict_types=1);

namespace Infocyph\InterMix\DI\Invoker;

use Infocyph\InterMix\DI\Resolver\Repository;
use Infocyph\InterMix\DI\Support\ReflectionResource;
use InvalidArgumentException;
use ReflectionException;

final readonly class GenericCall
{
    /**
     * Constructor for the GenericCall class.
     *
     * @param Repository $repository The repository instance used for resource management.
     */
    public function __construct(
        private Repository $repository,
    ) {}

    /**
     * Resolves a class instance (without any DI magic),
     * sets properties, and optionally invokes a method.
     *
     * @param string $class Fully-qualified class name to instantiate.
     * @param string|null $method Method to invoke, if any.
     * @return array{
     *     instance: object,
     *     returned: mixed
     * }
     *
     * @throws ReflectionException
     */
    public function classSettler(string $class, ?string $method = null): array
    {
        $classResource = $this->repository->getClassResourceFor($class);

        // Constructor parameters
        $ctorParams = $this->readNestedArray($classResource, ['constructor', 'params']);
        $instance = ReflectionResource::getClassReflection($class)->newInstanceArgs($ctorParams);

        // Set class properties (if any)
        $props = $this->readNestedArray($classResource, ['property']);
        $this->setProperties($instance, $props);

        // Determine method to invoke (method param, or classResource's configured "method", or defaultMethod)
        $method ??= $this->readNestedString($classResource, ['method', 'on']) ?? $this->repository->getDefaultMethod();
        $returned = $this->invokeMethod($instance, $method, $classResource);

        return [
            'instance' => $instance,
            'returned' => $returned,
        ];
    }

    /**
     * Executes a closure with given parameters and returns the result.
     *
     * @param callable $closure The closure to execute.
     * @param array<int|string, mixed> $params Additional parameters to pass to the closure.
     * @return mixed The result of calling the closure.
     */
    public function closureSettler(callable $closure, array $params = []): mixed
    {
        return $closure(...$params);
    }

    /**
     * Resolves a definition without dependency-injection semantics.
     *
     * @throws ReflectionException
     */
    public function resolveByDefinition(string $name): mixed
    {
        $definition = $this->repository->getFunctionDefinition($name);

        if ($definition instanceof \Closure) {
            return $definition();
        }

        if (is_array($definition)) {
            return $this->resolveArrayDefinition($definition);
        }

        return $this->resolveScalarDefinition($definition);
    }

    /**
     * Invokes a method on an object, with optional parameters.
     *
     * If the method does not exist, this method will simply return null.
     *
     * @param object $instance Object on which to invoke the method.
     * @param string|null $method Method to invoke (if null, no method is invoked).
     * @param array<string, mixed> $classResource Class resource with method parameter data.
     * @return mixed The result of the method invocation (or null if no method was invoked).
     */
    private function invokeMethod(object $instance, ?string $method, array $classResource): mixed
    {
        if (!$method || !method_exists($instance, $method)) {
            return null;
        }

        $params = $this->readNestedArray($classResource, ['method', 'params']);
        $reflectionMethod = ReflectionResource::getClassReflection($instance)->getMethod($method);

        return $reflectionMethod->invokeArgs($instance, $params);
    }

    /**
     * @param array<string, mixed> $source
     * @param array<int, string> $keys
     * @return array<int|string, mixed>
     */
    private function readNestedArray(array $source, array $keys): array
    {
        $value = $source;
        foreach ($keys as $key) {
            if (!is_array($value) || !array_key_exists($key, $value)) {
                return [];
            }
            $value = $value[$key];
        }

        return is_array($value) ? $value : [];
    }

    /**
     * @param array<string, mixed> $source
     * @param array<int, string> $keys
     */
    private function readNestedString(array $source, array $keys): ?string
    {
        $value = $source;
        foreach ($keys as $key) {
            if (!is_array($value) || !array_key_exists($key, $value)) {
                return null;
            }
            $value = $value[$key];
        }

        return is_string($value) ? $value : null;
    }

    /**
     * @param array<int|string, mixed> $definition
     *
     * @throws ReflectionException
     */
    private function resolveArrayDefinition(array $definition): mixed
    {
        $class = $definition[0] ?? null;
        if (!is_string($class) || !class_exists($class)) {
            return $definition;
        }

        $method = isset($definition[1]) && is_string($definition[1]) ? $definition[1] : null;
        $resolved = $this->classSettler($class, $method);

        return $method !== null ? $resolved['returned'] : $resolved['instance'];
    }

    /**
     * @throws ReflectionException
     */
    private function resolveScalarDefinition(mixed $definition): mixed
    {
        if (is_string($definition) && class_exists($definition)) {
            return $this->classSettler($definition)['instance'];
        }

        if (is_string($definition) && function_exists($definition)) {
            return $definition();
        }

        return $definition;
    }

    /**
     * Sets properties on an instance.
     *
     * @param object $instance Object to set properties on
     * @param array<int|string, mixed> $properties Properties to set
     *
     * @throws ReflectionException
     */
    private function setProperties(object $instance, array $properties): void
    {
        $refClass = ReflectionResource::getClassReflection($instance);

        foreach ($properties as $prop => $val) {
            if (!is_string($prop)) {
                continue;
            }
            if ($refClass->hasProperty($prop)) {
                $rProp = $refClass->getProperty($prop);

                // static vs non-static
                $target = $rProp->isStatic() ? null : $instance;
                $rProp->setValue($target, $val);
            } else {
                throw new InvalidArgumentException('Property ' . $prop . ' does not exist on class ' . $instance::class);
            }
        }
    }
}
