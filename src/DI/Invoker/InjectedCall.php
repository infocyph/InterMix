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
use WeakMap;

final readonly class InjectedCall
{
    private ClassResolver $classResolver;

    private DefinitionResolver $definitionResolver;

    /** @var WeakMap<Closure, int> */
    private WeakMap $parameterCountCache;

    private ParameterResolver $parameterResolver;

    /**
     * InjectedCall constructor.
     *
     * @param Repository $repository The DI repository which contains definitions, classes, functions, and parameters.
     */
    public function __construct(
        private Repository $repository,
    ) {
        $this->parameterCountCache = new WeakMap();
        $this->definitionResolver = new DefinitionResolver($this->repository);
        $this->parameterResolver = new ParameterResolver($this->repository, $this->definitionResolver);

        $propertyResolver = new PropertyResolver($this->repository);

        $this->classResolver = new ClassResolver(
            $this->repository,
            $this->parameterResolver,
            $propertyResolver,
            $this->definitionResolver,
        );

        $this->definitionResolver->setResolverInstance($this->classResolver, $this->parameterResolver);
        $this->parameterResolver->setClassResolverInstance($this->classResolver);
        $propertyResolver->setClassResolverInstance($this->classResolver);
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
        return $this->classResolver->resolve(
            ReflectionResource::getClassReflection($class),
            null,
            $method,
            $make,
        );
    }

    /**
     * Executes a closure (or function) with the given parameters and returns its result.
     *
     * @param callable $closure The callable to execute.
     * @param array<int|string, mixed> $params Additional parameters to be passed.
     * @return mixed The result of executing the closure/function.
     *
     * @throws ReflectionException|ContainerException|InvalidArgumentException
     */
    public function closureSettler(callable $closure, array $params = []): mixed
    {
        if ($closure instanceof Closure
            && ($this->parameterCountCache[$closure] ?? null) === 0
            && !$this->repository->isTracingEnabled()
        ) {
            return $closure();
        }

        $reflection = ReflectionResource::getCallableReflection($closure);
        if ($closure instanceof Closure) {
            $this->parameterCountCache[$closure] = $reflection->getNumberOfParameters();
        }
        if ($reflection->getNumberOfParameters() === 0 && !$this->repository->isTracingEnabled()) {
            return $closure();
        }

        return $closure(
            ...$this->parameterResolver->resolve(
                $reflection,
                $params,
                'constructor',
            ),
        );
    }

    /**
     * @return array{ClassResolver, ParameterResolver} Active reflection-backed resolvers.
     * @internal
     */
    public function reflectionResolvers(): array
    {
        return [$this->classResolver, $this->parameterResolver];
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
}
