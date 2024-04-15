<?php

namespace Infocyph\InterMix\DI\Invoker;

use Infocyph\InterMix\DI\Resolver\Repository;
use Closure;
use Exception;
use Error;

final readonly class GenericCall
{
    /**
     * A constructor for the class.
     *
     * @param Repository $repository The repository instance.
     */
    public function __construct(
        private Repository $repository
    ) {
    }

    /**
     * Generates a function comment for the given function body.
     *
     * @param string $class The class name.
     * @param string|null $method The method name. Defaults to null.
     * @return array The array containing the instantiated class, and the returned value from the method.
     * @throws Exception|Error If an error occurs while setting a property.
     */
    public function classSettler(string $class, string $method = null): array
    {
        $asset = [
            'instance' => $instance = new $class(
                ...($this->repository->classResource[$class]['constructor']['params'] ?? [])
            ),
            'returned' => null
        ];

        if (!empty($this->repository->classResource[$class]['property'])) {
            foreach ($this->repository->classResource[$class]['property'] as $item => $value) {
                try {
                    $instance->$item = $value;
                } catch (Exception|Error) {
                    $class::$$item = $value;
                }
            }
        }

        $method ??= $this->repository->classResource[$class]['method']['on']
            ?? $this->repository->defaultMethod;

        if (!empty($method) && method_exists($instance, $method)) {
            $asset['returned'] = $instance->$method(
                ...($this->repository->classResource[$class]['method']['params'] ?? [])
            );
        }

        return $asset;
    }

    /**
     * Executes a closure with the given parameters and returns the result.
     *
     * @param string|Closure $closure The closure to be executed.
     * @param array $params The parameters to be passed to the closure.
     * @return mixed The result of executing the closure.
     */
    public function closureSettler(string|Closure $closure, array $params): mixed
    {
        return $closure(...$params);
    }
}
