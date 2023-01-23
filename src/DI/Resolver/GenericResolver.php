<?php

namespace AbmmHasan\OOF\DI\Resolver;

use AbmmHasan\OOF\DI\Asset;
use Closure;

final class GenericResolver
{
    public function __construct(
        private Asset $containerAsset
    ) {
    }

    /**
     * Call the class
     *
     * @param string $class
     * @param string|null $method
     * @return object
     */
    public function classSettler(string $class, string $method = null): object
    {
        $asset = [
            'instance' => $instance = new $class(
                ...($this->containerAsset->classResource[$class]['constructor']['params'] ?? [])
            ),
            'returned' => null
        ];

        if (!empty($method) && method_exists($instance, $method)) {
            $asset['returned'] = $instance->$method(
                ...($this->containerAsset->classResource[$class]['method']['params'] ?? [])
            );
        }

        return (object)$asset;
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
