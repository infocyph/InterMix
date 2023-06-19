<?php

namespace AbmmHasan\InterMix\DI\Invoker;

use AbmmHasan\InterMix\DI\Resolver\Repository;
use Closure;
use Exception;
use Error;

final class GenericCall
{
    /**
     * @param Repository $repository the repository instance
     */
    public function __construct(
        private Repository $repository
    ) {
    }

    /**
     * Call the class
     *
     * @param string $class the class name
     * @param string|null $method method name within the class
     * @return array
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
                } catch (Exception|Error $e) {
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
     * call the closure
     *
     * @param string|Closure $closure the closure
     * @param array $params parameters to provide in closure
     * @return mixed
     */
    public function closureSettler(string|Closure $closure, array $params): mixed
    {
        return $closure(...$params);
    }
}
