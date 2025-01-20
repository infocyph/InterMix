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
use Psr\Cache\InvalidArgumentException;
use ReflectionException;

/**
 * InjectedCall is responsible for "injected" (dependency-injected) resolution
 * of classes, closures, and definitions using reflection-based resolvers.
 */
final readonly class InjectedCall
{
    private ParameterResolver   $parameterResolver;
    private ClassResolver       $classResolver;
    private DefinitionResolver  $definitionResolver;

    /**
     * Constructor for the InjectedCall class.
     *
     * @param  Repository  $repository
     */
    public function __construct(
        private Repository $repository
    ) {
        $this->initializeResolvers();
    }

    /**
     * Initialize the required resolvers (ClassResolver, ParameterResolver, DefinitionResolver).
     */
    private function initializeResolvers(): void
    {
        $this->definitionResolver = new DefinitionResolver($this->repository);
        $this->parameterResolver  = new ParameterResolver($this->repository, $this->definitionResolver);

        $propertyResolver = new PropertyResolver($this->repository, $this->parameterResolver);

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
     * Resolves a parameter by its definition name.
     *
     * @param  string  $name  The name or identifier to be resolved.
     * @return mixed  The resolved parameter.
     *
     * @throws ContainerException|ReflectionException|InvalidArgumentException
     */
    public function resolveByDefinition(string $name): mixed
    {
        return $this->definitionResolver->resolve($name);
    }

    /**
     * Settles (resolves) a class with dependency injection.
     *
     * @param  string|object  $class   The class name or object to settle.
     * @param  string|null    $method  The method to call after construction (or null).
     * @param  bool           $make    Whether to create a new instance (bypassing any cached instance).
     * @return array          An associative array with keys 'instance' and possibly 'returned'.
     *
     * @throws ContainerException|ReflectionException|InvalidArgumentException
     */
    public function classSettler(
        string|object $class,
        ?string $method = null,
        bool $make = false
    ): array {
        // Use ReflectionResource directly
        $reflection = ReflectionResource::getClassReflection($class);

        // Let ClassResolver do the rest
        return $this->classResolver->resolve($reflection, null, $method, $make);
    }

    /**
     * Executes a closure (or function) with the given parameters and returns its result.
     *
     * @param  string|Closure  $closure  The closure or function name to be executed.
     * @param  array           $params   Additional parameters to be passed.
     * @return mixed           The result of executing the closure/function.
     *
     * @throws ReflectionException|ContainerException|InvalidArgumentException
     */
    public function closureSettler(string|Closure $closure, array $params = []): mixed
    {
        // Reflect the closure or function
        $reflection = ReflectionResource::getFunctionReflection($closure);

        // Resolve parameters for "constructor" context or whatever you prefer as the default
        $resolvedParams = $this->parameterResolver->resolve($reflection, $params, 'constructor');

        // Invoke the closure with resolved arguments
        return $closure(...$resolvedParams);
    }
}
