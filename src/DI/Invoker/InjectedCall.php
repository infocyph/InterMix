<?php

declare(strict_types=1);

namespace Infocyph\InterMix\DI\Invoker;

use Closure;
use Infocyph\InterMix\DI\Reflection\ReflectionResource;
use Infocyph\InterMix\DI\Resolver\ClassResolver;
use Infocyph\InterMix\DI\Resolver\DefinitionResolver;
use Infocyph\InterMix\DI\Resolver\ParameterResolver;
use Infocyph\InterMix\DI\Resolver\PropertyResolver;
use Infocyph\InterMix\DI\Resolver\Repository;
use Infocyph\InterMix\Exceptions\ContainerException;
use ReflectionException;

final readonly class InjectedCall
{
    private ParameterResolver $parameterResolver;
    private ClassResolver $classResolver;
    private DefinitionResolver $definitionResolver;


    /**
     * @param Repository $repository
     */
    public function __construct(
        private Repository $repository
    ) {
        $this->initializeResolvers();
    }


    /**
     * Initialize resolvers required for injected method calls.
     *
     * Creates instances for DefinitionResolver, ParameterResolver, PropertyResolver, and ClassResolver.
     * Then, injects references back to each other for cross-communication.
     */
    private function initializeResolvers(): void
    {
        $this->definitionResolver = new DefinitionResolver($this->repository);
        $this->parameterResolver = new ParameterResolver($this->repository, $this->definitionResolver);

        $propertyResolver = new PropertyResolver($this->repository);

        $this->classResolver = new ClassResolver(
            $this->repository,
            $this->parameterResolver,
            $propertyResolver,
            $this->definitionResolver
        );

        // Inject references back for cross-communication
        $this->definitionResolver->setResolverInstance($this->classResolver, $this->parameterResolver);
        $this->parameterResolver->setClassResolverInstance($this->classResolver);
        $propertyResolver->setClassResolverInstance($this->classResolver);
    }


    /**
     * Resolve a definition by name (id).
     *
     * @param string $name The id of the definition to resolve.
     *
     * @return mixed The resolved value of the definition.
     * @throws ContainerException
     */
    public function resolveByDefinition(string $name): mixed
    {
        return $this->definitionResolver->resolve($name);
    }

    /**
     * Settles (resolves) a class with dependency injection.
     *
     * @param  string|object  $class  The class name or object to settle.
     * @param  string|null  $method  The method to call after construction (or null).
     * @param  bool  $make  Whether to create a new instance (bypassing any cached instance).
     * @return array An associative array with keys 'instance' and possibly 'returned'.
     *
     * @throws ReflectionException|ContainerException
     */
    public function classSettler(
        string|object $class,
        ?string $method = null,
        bool $make = false
    ): array {
        return $this->classResolver->resolve(
            ReflectionResource::getClassReflection($class),
            null,
            $method,
            $make
        );
    }

    /**
     * Executes a closure (or function) with the given parameters and returns its result.
     *
     * @param  string|Closure  $closure  The closure or function name to be executed.
     * @param  array  $params  Additional parameters to be passed.
     * @return mixed The result of executing the closure/function.
     *
     * @throws ReflectionException|ContainerException
     */
    public function closureSettler(string|Closure $closure, array $params = []): mixed
    {
        // Invoke the closure with resolved arguments
        return $closure(
            ...$this->parameterResolver->resolve(
                ReflectionResource::getFunctionReflection($closure),
                $params,
                'constructor'
            )
        );
    }
}
