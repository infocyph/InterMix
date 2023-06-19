<?php

namespace AbmmHasan\InterMix\DI\Invoker;

use AbmmHasan\InterMix\DI\Resolver\ClassResolver;
use AbmmHasan\InterMix\DI\Resolver\ParameterResolver;
use AbmmHasan\InterMix\DI\Resolver\PropertyResolver;
use AbmmHasan\InterMix\DI\Resolver\Reflector;
use AbmmHasan\InterMix\DI\Resolver\Repository;
use AbmmHasan\InterMix\Exceptions\ContainerException;
use Closure;
use ReflectionException;
use ReflectionFunction;

final class InjectedCall
{
    use Reflector;

    private ParameterResolver $parameterResolver;
    private ClassResolver $classResolver;
    private PropertyResolver $propertyResolver;

    /**
     * @param Repository $repository
     */
    public function __construct(
        private Repository $repository
    ) {
        $this->parameterResolver = new ParameterResolver($this->repository);
        $this->propertyResolver = new PropertyResolver(
            $this->repository,
            $this->parameterResolver
        );
        $this->classResolver = new ClassResolver(
            $this->repository,
            $this->parameterResolver,
            $this->propertyResolver
        );
        $this->parameterResolver->setClassResolverInstance($this->classResolver);
        $this->propertyResolver->setClassResolverInstance($this->classResolver);
    }

    /**
     * Definition based resolver
     *
     * @param string $name
     * @return mixed
     * @throws ReflectionException|ContainerException
     */
    public function resolveByDefinition(string $name): mixed
    {
        return $this->parameterResolver->prepareDefinition($name);
    }

    /**
     * Settle class dependency and resolve thorough
     *
     * @param string $class
     * @param string|null $method
     * @param bool $make
     * @return array
     * @throws ReflectionException|ContainerException
     */
    public function classSettler(string $class, string $method = null, bool $make = false): array
    {
        return $this->classResolver->resolve($this->reflectedClass($class), null, $method, $make);
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
            ...$this->parameterResolver->resolve(new ReflectionFunction($closure), $params, 'constructor')
        );
    }
}
