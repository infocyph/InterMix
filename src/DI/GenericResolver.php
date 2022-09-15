<?php

namespace AbmmHasan\OOF\DI;

use Closure;

final class GenericResolver
{
    public function __construct(
        private Container $container
    )
    {

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
        return [
            'instance' => $instance = new $class(
                ...($this->container->classResource[$class]['constructor']['params'] ?? [])
            ),
            'returned' => $method === null ? null : $instance->$method(
                ...($this->container->classResource[$class]['method']['params'] ?? [])
            )
        ];
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
