<?php

declare(strict_types=1);

namespace Infocyph\InterMix\DI\Resolver;

use Closure;
use Infocyph\InterMix\DI\Reflection\ReflectionResource;
use Infocyph\InterMix\Exceptions\ContainerException;
use Psr\Cache\InvalidArgumentException;
use ReflectionException;
use ReflectionFunction;

class DefinitionResolver
{
    private ClassResolver      $classResolver;
    private ParameterResolver  $parameterResolver;

    /**
     * Tracks definitions currently being resolved to detect circular dependencies.
     *
     * @var array<string,bool>
     */
    private array $entriesResolving = [];

    /**
     * @param  Repository  $repository
     */
    public function __construct(
        private readonly Repository $repository
    ) {
        //
    }

    /**
     * Set the ClassResolver and ParameterResolver instances so we can delegate to them.
     */
    public function setResolverInstance(
        ClassResolver $classResolver,
        ParameterResolver $parameterResolver
    ): void {
        $this->classResolver    = $classResolver;
        $this->parameterResolver = $parameterResolver;
    }

    /**
     * Resolve a definition by its name (ID).
     *
     * @param  string  $name
     * @return mixed
     *
     * @throws ReflectionException|ContainerException|InvalidArgumentException
     */
    public function resolve(string $name): mixed
    {
        // Check for circular reference
        if (isset($this->entriesResolving[$name])) {
            throw new ContainerException(
                "Circular dependency detected while resolving definition '$name'."
            );
        }

        $this->entriesResolving[$name] = true;
        try {
            return $this->getFromCacheOrResolve($name);
        } finally {
            unset($this->entriesResolving[$name]);
        }
    }

    /**
     * Retrieve a definition from the repository’s definition cache if present;
     * otherwise resolve it and store it in the cache.
     *
     * @throws ReflectionException|ContainerException|InvalidArgumentException
     */
    private function getFromCacheOrResolve(string $name): mixed
    {
        // Check if already resolved
        $resolvedDefs = $this->repository->getResolvedDefinition();
        if (! isset($resolvedDefs[$name])) {
            $resolverCallback = fn () => $this->resolveDefinition($name);

            $cacheAdapter = $this->repository->getCacheAdapter();
            if ($cacheAdapter) {
                // Build a cache key using the repository alias
                $cacheKey = $this->repository->getAlias() . '-def' . base64_encode($name);

                // Use the cache to store/fetch
                $value = $cacheAdapter->get($cacheKey, $resolverCallback);
                $this->repository->setResolvedDefinition($name, $value);
            } else {
                // No cache adapter => directly resolve
                $value = $resolverCallback();
                $this->repository->setResolvedDefinition($name, $value);
            }
        }

        return $this->repository->getResolvedDefinition()[$name];
    }

    /**
     * Actually resolve a definition by looking up the definition in functionReference
     * and handling different types (Closure, array, class-string, etc.).
     *
     * @throws ReflectionException|ContainerException|InvalidArgumentException
     */
    private function resolveDefinition(string $name): mixed
    {
        // Retrieve the definition from repository->functionReference
        $definition = $this->repository->getFunctionReference()[$name] ?? null;

        return match (true) {
            // If it's a closure => call it, injecting parameters
            $definition instanceof Closure =>
            $definition(...$this->parameterResolver->resolve(
                new ReflectionFunction($definition),
                [],
                'constructor'
            )),

            // If it's an array with [className, ???], and that className exists => handle array definition
            is_array($definition) && isset($definition[0]) && class_exists($definition[0]) =>
            $this->resolveArrayDefinition($definition),

            // If it's a string and that string is a valid class => use ClassResolver
            is_string($definition) && class_exists($definition) =>
            $this->classResolver->resolve(
                ReflectionResource::getClassReflection($definition)
            )['instance'],

            // Otherwise, just return the definition as-is (e.g. scalar or non-class string)
            default => $definition,
        };
    }

    /**
     * Resolve an array-based definition: e.g. ['Some\Class', true/falseOrMethod].
     *
     * @param  array  $definition  e.g. [className, boolOrMethod], or [className]
     * @return mixed
     *
     * @throws ReflectionException|ContainerException|InvalidArgumentException
     */
    private function resolveArrayDefinition(array $definition): mixed
    {
        // $definition[0] => className
        // $definition[1] => could be method name or bool, etc.
        $resolved = $this->classResolver->resolve(...$definition);

        // If the second element of $definition is truthy => return 'returned', else 'instance'
        return !empty($definition[1]) ? $resolved['returned'] : $resolved['instance'];
    }
}
