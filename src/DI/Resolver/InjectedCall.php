<?php

namespace AbmmHasan\OOF\DI\Resolver;

use AbmmHasan\OOF\Exceptions\ContainerException;
use Closure;
use ReflectionException;
use ReflectionFunction;

final class InjectedCall extends DependencyResolver
{

    /**
     * Settle class dependency and resolve thorough
     *
     * @param string $class
     * @param string|null $method
     * @return array
     * @throws ContainerException|ReflectionException
     */
    public function classSettler(string $class, string $method = null): array
    {
        return $this->getResolvedInstance($this->reflectedClass($class), null, $method);
    }

    /**
     * Settle closure dependency and resolve thorough
     *
     * @param string|Closure $closure
     * @param array $params
     * @return mixed
     * @throws ReflectionException|ContainerException
     */
    public function closureSettler(string|Closure $closure, array $params = []): mixed
    {
        return $closure(
            ...$this->resolveParameters(new ReflectionFunction($closure), $params, 'constructor')
        );
    }

    /**
     * Definition based resolver
     *
     * @param mixed $definition
     * @param string $name
     * @return mixed
     * @throws ReflectionException|ContainerException
     */
    public function resolveByDefinition(mixed $definition, string $name): mixed
    {
        return $this->resolvedDefinition[$name] ??= match (true) {
            $definition instanceof Closure => $this->closureSettler($$name = $definition),

            is_array($definition) && class_exists($definition[0]) => function () use ($definition) {
                $resolved = $this->classSettler(...$definition);
                return empty($definition[1]) ? $resolved['instance'] : $resolved['returned'];
            },

            is_string($definition) && class_exists($definition) => $this->classSettler($definition)['instance'],

            default => $definition
        };
    }
}
