<?php

namespace AbmmHasan\InterMix\DI\Resolver;

use AbmmHasan\InterMix\Exceptions\ContainerException;
use Closure;
use ReflectionException;
use ReflectionFunction;

final class InjectedCall
{
    use ReflectionResource;

    private ParameterResolver $parameterResolver;
    private ClassResolver $classResolver;

    /**
     * @param Repository $repository
     */
    public function __construct(
        private Repository $repository
    ) {
        $this->parameterResolver = new ParameterResolver($this->repository);
        $this->classResolver = new ClassResolver($this->repository, $this->parameterResolver);
        $this->parameterResolver->setClassResolverInstance($this->classResolver);
    }

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
        return $this->classResolver->resolve($this->reflectedClass($class), null, $method);
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
