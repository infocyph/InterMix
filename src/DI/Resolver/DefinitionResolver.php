<?php

namespace Infocyph\InterMix\DI\Resolver;

use Closure;
use Infocyph\InterMix\Exceptions\ContainerException;
use Psr\Cache\InvalidArgumentException;
use ReflectionException;
use ReflectionFunction;

class DefinitionResolver
{
    use Reflector;

    private ClassResolver $classResolver;

    private ParameterResolver $parameterResolver;

    private array $entriesResolving = [];

    /**
     * Constructor for the class.
     *
     * @param  Repository  $repository  The repository object.
     */
    public function __construct(private readonly Repository $repository)
    {
    }

    /**
     * Sets the ClassResolver and ParameterResolver instances.
     *
     * @param  ClassResolver  $classResolver  The ClassResolver instance.
     * @param  ParameterResolver  $parameterResolver  The ParameterResolver instance.
     */
    public function setResolverInstance(
        ClassResolver $classResolver,
        ParameterResolver $parameterResolver
    ): void {
        $this->classResolver = $classResolver;
        $this->parameterResolver = $parameterResolver;
    }

    /**
     * Resolve a definition by its name.
     *
     * @param  string  $name  The name of the definition.
     * @return mixed The resolved definition.
     *
     * @throws ReflectionException|ContainerException|InvalidArgumentException
     */
    public function resolve(string $name): mixed
    {
        if (isset($this->entriesResolving[$name])) {
            throw new ContainerException("Circular dependency detected while resolving definition '$name'.");
        }

        $this->entriesResolving[$name] = true;

        try {
            return $this->getFromCacheOrResolve($name);
        } finally {
            unset($this->entriesResolving[$name]);
        }
    }

    /**
     * Get a value from cache or resolve it if not cached.
     *
     * @param  string  $name  The name to resolve.
     * @return mixed The resolved value.
     *
     * @throws ReflectionException|ContainerException|InvalidArgumentException
     */
    private function getFromCacheOrResolve(string $name): mixed
    {
        if (! isset($this->repository->resolvedDefinition[$name])) {
            $resolver = fn () => $this->resolveDefinition($name);

            $this->repository->resolvedDefinition[$name] = isset($this->repository->cacheAdapter)
                ? $this->repository->cacheAdapter->get(
                    $this->repository->alias.'-def'.base64_encode($name),
                    $resolver
                )
                : $resolver();
        }

        return $this->repository->resolvedDefinition[$name];
    }

    /**
     * Resolve a definition.
     *
     * @param  string  $name  The name of the definition.
     * @return mixed The resolved definition.
     *
     * @throws ReflectionException|ContainerException|InvalidArgumentException
     */
    private function resolveDefinition(string $name): mixed
    {
        $definition = $this->repository->functionReference[$name] ?? null;

        return match (true) {
            $definition instanceof Closure => $definition(
                ...$this->parameterResolver->resolve(
                    new ReflectionFunction($definition),
                    [],
                    'constructor'
                )
            ),
            is_array($definition) && class_exists($definition[0]) => $this->resolveArrayDefinition($definition),
            is_string($definition) && class_exists($definition) => $this->classResolver
                ->resolve($this->reflectedClass($definition))['instance'],
            default => $definition
        };
    }

    /**
     * Resolve an array-based definition.
     *
     * @param  array  $definition  The array definition.
     * @return mixed The resolved instance or returned value.
     *
     * @throws ReflectionException|ContainerException|InvalidArgumentException
     */
    private function resolveArrayDefinition(array $definition): mixed
    {
        $resolved = $this->classResolver->resolve(...$definition);

        return $definition[1] ?? false ? $resolved['returned'] : $resolved['instance'];
    }
}
