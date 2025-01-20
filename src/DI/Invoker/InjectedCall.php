<?php

namespace Infocyph\InterMix\DI\Invoker;

use Closure;
use Infocyph\InterMix\DI\Resolver\ClassResolver;
use Infocyph\InterMix\DI\Resolver\DefinitionResolver;
use Infocyph\InterMix\DI\Resolver\ParameterResolver;
use Infocyph\InterMix\DI\Resolver\PropertyResolver;
use Infocyph\InterMix\DI\Resolver\Reflector;
use Infocyph\InterMix\DI\Resolver\Repository;
use Infocyph\InterMix\Exceptions\ContainerException;
use Psr\Cache\InvalidArgumentException;
use ReflectionException;
use ReflectionFunction;

final readonly class InjectedCall
{
    use Reflector;

    private ParameterResolver $parameterResolver;

    private ClassResolver $classResolver;

    private DefinitionResolver $definitionResolver;

    /**
     * Constructor for the InjectedCall class.
     */
    public function __construct(
        private Repository $repository
    ) {
        $this->initializeResolvers();
    }

    /**
     * Initialize the required resolvers.
     */
    private function initializeResolvers(): void
    {
        $this->definitionResolver = new DefinitionResolver($this->repository);
        $this->parameterResolver = new ParameterResolver($this->repository, $this->definitionResolver);
        $propertyResolver = new PropertyResolver($this->repository, $this->parameterResolver);
        $this->classResolver = new ClassResolver(
            $this->repository,
            $this->parameterResolver,
            $propertyResolver,
            $this->definitionResolver
        );

        $this->definitionResolver->setResolverInstance($this->classResolver, $this->parameterResolver);
        $this->parameterResolver->setClassResolverInstance($this->classResolver);
        $propertyResolver->setClassResolverInstance($this->classResolver);
    }

    /**
     * Resolves a parameter by its definition.
     *
     * @param  string  $name  The name of the parameter.
     * @return mixed The resolved parameter.
     *
     * @throws ContainerException|ReflectionException|InvalidArgumentException
     */
    public function resolveByDefinition(string $name): mixed
    {
        return $this->definitionResolver->resolve($name);
    }

    /**
     * Settle class dependency injection.
     *
     * @param  string|object  $class  The class or object to settle.
     * @param  string|null  $method  The method to resolve.
     * @param  bool  $make  Whether to create a new instance of the class if it exists.
     * @return array The resolved class.
     *
     * @throws ContainerException|ReflectionException|InvalidArgumentException
     */
    public function classSettler(string|object $class, ?string $method = null, bool $make = false): array
    {
        return $this->classResolver->resolve($this->reflectedClass($class), null, $method, $make);
    }

    /**
     * Executes a closure with the given parameters and returns the result.
     *
     * @param  string|Closure  $closure  The closure to be executed.
     * @param  array  $params  The parameters to be passed to the closure.
     * @return mixed The result of executing the closure.
     *
     * @throws ReflectionException|ContainerException|InvalidArgumentException
     */
    public function closureSettler(string|Closure $closure, array $params = []): mixed
    {
        $reflection = new ReflectionFunction($closure);
        $resolvedParams = $this->parameterResolver->resolve($reflection, $params, 'constructor');

        return $closure(...$resolvedParams);
    }
}
