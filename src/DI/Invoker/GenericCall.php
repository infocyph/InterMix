<?php

namespace AbmmHasan\InterMix\DI\Invoker;

use AbmmHasan\InterMix\DI\Resolver\Repository;
use Closure;

final class GenericCall
{
    /**
     * @param Repository $repository
     */
    public function __construct(
        private Repository $repository
    ) {
    }

    /**
     * Call the class
     *
     * @param string $class
     * @param string|null $method
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
                if (property_exists($instance, $item)) {
                    $instance->$item = $value;
                    continue;
                }

                if (property_exists($class, $item)) {
                    $class::$$item = $value;
                }
            }
        }

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
     * @param string|Closure $closure
     * @param array $params
     * @return mixed
     */
    public function closureSettler(string|Closure $closure, array $params): mixed
    {
        return $closure(...$params);
    }
}
