<?php

namespace Infocyph\InterMix\DI\Invoker;

use Error;
use Exception;
use Infocyph\InterMix\DI\Resolver\Repository;

final readonly class GenericCall
{
    /**
     * Constructor for the GenericCall class.
     *
     * @param Repository $repository The repository instance used for resource management.
     */
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
        $classResource = $this->repository->getClassResource()[$class] ?? [];

        // Constructor parameters
        $ctorParams = $classResource['constructor']['params'] ?? [];
        $instance = new $class(...$ctorParams);

        // Set class properties (if any)
        $props = $classResource['property'] ?? [];
        $this->setProperties($instance, $props);

        // Determine method to invoke (method param, or classResource's configured "method", or defaultMethod)
        $method ??= $classResource['method']['on'] ?? $this->repository->getDefaultMethod();
        $returned = $this->invokeMethod($instance, $method, $classResource);

        return [
            'instance' => $instance,
            'returned' => $returned,
        ];
    }


    /**
     * Executes a closure with given parameters and returns the result.
     *
     * @param  callable  $closure  The closure to execute.
     * @param  array  $params  Additional parameters to pass to the closure.
     * @return mixed The result of calling the closure.
     */
    public function closureSettler(callable $closure, array $params = []): mixed
    {
        return $closure(...$params);
    }


    /**
     * Sets properties on an instance.
     *
     * @param object $instance Object to set properties on
     * @param array $properties Properties to set
     * @return void
     */
    private function setProperties(object $instance, array $properties): void
    {
        foreach ($properties as $property => $value) {
            try {
                $instance->$property = $value;
            } catch (Exception|Error) {
                $className = $instance::class;
                $className::$$property = $value;
            }
        }
    }


    /**
     * Invokes a method on an object, with optional parameters.
     *
     * If the method does not exist, this method will simply return null.
     *
     * @param  object  $instance  Object on which to invoke the method.
     * @param  string|null  $method  Method to invoke (if null, no method is invoked).
     * @param  array  $classResource  Class resource with method parameter data.
     * @return mixed The result of the method invocation (or null if no method was invoked).
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
