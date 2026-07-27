<?php

declare(strict_types=1);

namespace Infocyph\InterMix\DI\Invoker;

use Closure;
use Infocyph\InterMix\DI\Resolver\ClassResolver;
use Infocyph\InterMix\DI\Resolver\DefinitionResolver;
use Infocyph\InterMix\DI\Resolver\ParameterResolver;
use Infocyph\InterMix\DI\Resolver\PropertyResolver;
use Infocyph\InterMix\DI\Resolver\Repository;
use Infocyph\InterMix\DI\Support\ReflectionResource;
use Infocyph\InterMix\Exceptions\ContainerException;
use Psr\Cache\InvalidArgumentException;
use ReflectionException;

final class InjectedCall
{
    private readonly DefinitionResolver $definitionResolver;

    private ?ClassResolver $classResolver = null;

    private ?ParameterResolver $parameterResolver = null;

    /**
     * InjectedCall constructor.
     *
     * @param Repository $repository The DI repository which contains definitions, classes, functions, and parameters.
     */
    public function __construct(
        private readonly Repository $repository,
    ) {
        $this->definitionResolver = new DefinitionResolver($this->repository);
        $this->definitionResolver->setResolverInitializer($this->initializeSupportingResolvers(...));
    }

    /**
     * Settles (resolves) a class with dependency injection.
     *
     * @param string|object $class The class name or object to settle.
     * @param string|null $method The method to call after construction (or null).
     * @param bool $make Whether to create a new instance (bypassing any cached instance).
     * @return array<string, mixed> An associative array with keys 'instance' and possibly 'returned'.
     *
     * @throws ReflectionException|ContainerException
     */
    public function classSettler(
        string|object $class,
        ?string $method = null,
        bool $make = false,
    ): array {
        [$classResolver] = $this->supportingResolvers();

        return $classResolver->resolve(
            ReflectionResource::getClassReflection($class),
            null,
            $method,
            $make,
        );
    }

    /**
     * Executes a closure (or function) with the given parameters and returns its result.
     *
     * @param string|Closure $closure The closure or function name to be executed.
     * @param array<int|string, mixed> $params Additional parameters to be passed.
     * @return mixed The result of executing the closure/function.
     *
     * @throws ReflectionException|ContainerException|InvalidArgumentException
     */
    public function closureSettler(string|Closure $closure, array $params = []): mixed
    {
        [, $parameterResolver] = $this->supportingResolvers();

        if (is_string($closure)) {
            if (!function_exists($closure)) {
                throw new ContainerException("Function '$closure' is not defined.");
            }
            $closure = $closure(...);
        }

        // Invoke the closure with resolved arguments
        return $closure(
            ...$parameterResolver->resolve(
                ReflectionResource::getFunctionReflection($closure),
                $params,
                'constructor',
            ),
        );
    }

    /**
     * Resolve a definition by name (id).
     *
     * @param string $name The id of the definition to resolve.
     *
     * @return mixed The resolved value of the definition.
     * @throws ContainerException|InvalidArgumentException|ReflectionException
     */
    public function resolveByDefinition(string $name): mixed
    {
        return $this->definitionResolver->resolve($name);
    }

    /**
     * Initialize resolvers required for injected method calls.
     *
     * Creates instances for DefinitionResolver, ParameterResolver, PropertyResolver, and ClassResolver.
     * Then, injects references back to each other for cross-communication.
     */
    private function initializeSupportingResolvers(): void
    {
        if ($this->classResolver instanceof ClassResolver && $this->parameterResolver instanceof ParameterResolver) {
            return;
        }

        $this->parameterResolver = new ParameterResolver($this->repository, $this->definitionResolver);

        $propertyResolver = new PropertyResolver($this->repository);

        $this->classResolver = new ClassResolver(
            $this->repository,
            $this->parameterResolver,
            $propertyResolver,
            $this->definitionResolver,
        );

        // Inject references back for cross-communication
        $this->definitionResolver->setResolverInstance($this->classResolver, $this->parameterResolver);
        $this->parameterResolver->setClassResolverInstance($this->classResolver);
        $propertyResolver->setClassResolverInstance($this->classResolver);
    }

    /**
     * @return array{ClassResolver, ParameterResolver}
     */
    private function supportingResolvers(): array
    {
        $this->initializeSupportingResolvers();

        return [
            $this->classResolver ?? throw new ContainerException('Class resolver is unavailable.'),
            $this->parameterResolver ?? throw new ContainerException('Parameter resolver is unavailable.'),
        ];
    }
}
