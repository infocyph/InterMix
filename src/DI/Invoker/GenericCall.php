<?php

namespace Infocyph\InterMix\DI\Invoker;

use Closure;
use Error;
use Exception;
use Infocyph\InterMix\DI\Resolver\Repository;

final readonly class GenericCall
{
    public function __construct(
        private Repository $repository
    ) {
    }

    /**
     * Resolves a class instance (without any DI magic),
     * sets properties, and optionally invokes a method.
     *
     * @param  string  $class  Fully-qualified class name to instantiate.
     * @param  string|null  $method  Method to invoke, if any.
     * @return array{
     *     instance: object,
     *     returned: mixed
     * }
     */
    public function classSettler(string $class, ?string $method = null): array
    {
        // Grab class info if present, else an empty array
        $classResource = $this->repository->classResource[$class] ?? [];

        // Constructor parameters
        $ctorParams = $classResource['constructor']['params'] ?? [];
        $instance = new $class(...$ctorParams);

        // Set class properties (if any)
        $props = $classResource['property'] ?? [];
        $this->setProperties($instance, $props);

        // Determine method to invoke (method param, or classResource's configured "method", or defaultMethod)
        $method ??= $classResource['method']['on'] ?? $this->repository->defaultMethod;
        $returned = $this->invokeMethod($instance, $method, $classResource);

        return [
            'instance' => $instance,
            'returned' => $returned,
        ];
    }

    /**
     * Executes a closure or callable with the provided parameters and returns the result.
     *
     * @param callable $closure
     * @param array $params
     * @return mixed
     */
    public function closureSettler(callable $closure, array $params = []): mixed
    {
        return $closure(...$params);
    }

    /**
     * Sets public properties on the object or static properties if needed.
     *
     * @param  array  $properties  [propertyName => value, ...]
     */
    private function setProperties(object $instance, array $properties): void
    {
        foreach ($properties as $property => $value) {
            try {
                // Attempt to set object property
                $instance->$property = $value;
            } catch (Exception|Error) {
                // If it's a static property or protected context
                $className = $instance::class;
                $className::$$property = $value;
            }
        }
    }

    /**
     * Invokes a method on the given object if it exists in the classResource.
     */
    private function invokeMethod(object $instance, ?string $method, array $classResource): mixed
    {
        if (! $method || ! method_exists($instance, $method)) {
            return null;
        }

        $params = $classResource['method']['params'] ?? [];

        return $instance->$method(...$params);
    }
}
