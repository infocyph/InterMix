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
     * Resolves a class instance and executes its properties and method.
     *
     * @param  string  $class  The class name.
     * @param  string|null  $method  The method name (optional).
     * @return array The array containing the instantiated class and the method return value (if any).
     */
    public function classSettler(string $class, ?string $method = null): array
    {
        $instance = $this->createInstance($class);
        $this->setProperties($instance, $class);
        $method ??= $this->getDefaultMethod($class);

        return [
            'instance' => $instance,
            'returned' => $this->invokeMethod($instance, $class, $method),
        ];
    }

    /**
     * Executes a closure with the provided parameters and returns the result.
     *
     * @param  string|Closure  $closure  The closure or callable.
     * @param  array  $params  Parameters to pass to the closure.
     * @return mixed The result of executing the closure.
     */
    public function closureSettler(string|Closure $closure, array $params): mixed
    {
        return $closure(...$params);
    }

    /**
     * Creates an instance of the given class.
     *
     * @param  string  $class  The class name.
     * @return object The instantiated class object.
     */
    private function createInstance(string $class): object
    {
        $params = $this->repository->classResource[$class]['constructor']['params'] ?? [];

        return new $class(...$params);
    }

    /**
     * Sets the properties of a class instance.
     *
     * @param  object  $instance  The class instance.
     * @param  string  $class  The class name.
     */
    private function setProperties(object $instance, string $class): void
    {
        $properties = $this->repository->classResource[$class]['property'] ?? [];

        foreach ($properties as $property => $value) {
            try {
                $instance->$property = $value;
            } catch (Exception|Error) {
                $class::$$property = $value;
            }
        }
    }

    /**
     * Retrieves the default method for a class if defined.
     *
     * @param  string  $class  The class name.
     * @return string|null The default method name or null if not defined.
     */
    private function getDefaultMethod(string $class): ?string
    {
        return $this->repository->classResource[$class]['method']['on']
            ?? $this->repository->defaultMethod;
    }

    /**
     * Invokes a method on a class instance.
     *
     * @param  object  $instance  The class instance.
     * @param  string  $class  The class name.
     * @param  string|null  $method  The method name.
     * @return mixed The method's return value or null if the method does not exist.
     */
    private function invokeMethod(object $instance, string $class, ?string $method): mixed
    {
        if (! empty($method) && method_exists($instance, $method)) {
            $params = $this->repository->classResource[$class]['method']['params'] ?? [];

            return $instance->$method(...$params);
        }

        return null;
    }
}
