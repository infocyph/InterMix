<?php

declare(strict_types=1);

namespace Infocyph\InterMix\DI\Resolver;

use Closure;
use Infocyph\InterMix\DI\Support\Lifetime;
use Infocyph\InterMix\DI\Support\ReflectionResource;
use Infocyph\InterMix\Exceptions\ContainerException;
use Psr\Cache\InvalidArgumentException;
use ReflectionException;

class DefinitionResolver
{
    private array $entriesResolving = [];
    private ClassResolver $classResolver;
    private ParameterResolver $parameterResolver;

    public function __construct(
        private readonly Repository $repository,
    ) {
    }

    /**
     * Sets the ClassResolver and ParameterResolver instances on this object.
     *
     * These resolvers are used by the resolve() method to resolve definitions
     * that are class names or functions, and to resolve function parameters that
     * are not provided by the user.
     *
     * @param ClassResolver $classResolver The ClassResolver instance.
     * @param ParameterResolver $parameterResolver The ParameterResolver instance.
     */
    public function setResolverInstance(
        ClassResolver $classResolver,
        ParameterResolver $parameterResolver,
    ): void {
        $this->classResolver = $classResolver;
        $this->parameterResolver = $parameterResolver;
    }


    /**
     * Resolve a definition by its name.
     *
     * First, check if the definition has already been resolved and stored in the
     * repository. If so, return the stored result.
     * If not, call the "getFromCacheOrResolve" method to resolve the definition.
     * If the definition is still being resolved (circular dependency), throw an
     * exception.
     *
     * @param string $name The name of the definition to resolve.
     * @return mixed The resolved value of the definition.
     * @throws ContainerException|InvalidArgumentException|ReflectionException
     */
    public function resolve(string $name): mixed
    {
        if (isset($this->entriesResolving[$name])) {
            throw new ContainerException("Circular dependency for definition '$name'.");
        }
        $this->entriesResolving[$name] = true;
        $this->repository->tracer()->push("def:$name");
        try {
            return $this->getFromCacheOrResolve($name);
        } finally {
            unset($this->entriesResolving[$name]);
        }
    }

    /**
     * Tries to get a definition from the cache, otherwise resolves it using the
     * `resolveDefinition` method and caches the result.
     *
     * @param string $name The name of the definition to resolve.
     * @return mixed The resolved value of the definition.
     * @throws ContainerException
     * @throws InvalidArgumentException
     * @throws ReflectionException
     */
    private function getFromCacheOrResolve(string $name): mixed
    {
        $lifetime = $this->repository->getDefinitionMeta($name)['lifetime'] ?? Lifetime::Singleton;

        // transient / scoped → never cache at this layer
        if ($lifetime !== Lifetime::Singleton) {
            return $this->resolveDefinition($name);
        }

        $resolvedDefs = $this->repository->getResolvedDefinition();
        if (!isset($resolvedDefs[$name])) {
            $resolverCallback = fn () => $this->resolveDefinition($name);
            $cacheAdapter = $this->repository->getCacheAdapter();
            if ($cacheAdapter) {
                $cacheKey = $this->repository->makeCacheKey('def' . base64_encode($name));
                $value = $cacheAdapter->get($cacheKey, $resolverCallback);
            } else {
                $value = $resolverCallback();
            }
            $this->repository->setResolvedDefinition($name, $value);
        }
        return $this->repository->getResolvedDefinition()[$name];
    }

    /**
     * Resolves a definition by its ID and returns the resolved value.
     *
     * This method resolves a definition by its ID. If the definition is a closure,
     * it is called with resolved arguments. If the definition is an array where the
     * first element is a class name, it is resolved as an array definition. If the
     * definition is a string class name, it is resolved as a class. Otherwise, the
     * definition is returned as is.
     *
     * @param string $name The name of the definition to resolve.
     * @return mixed The resolved value of the definition.
     * @throws ContainerException
     * @throws ReflectionException|InvalidArgumentException
     */
    private function resolveDefinition(string $name): mixed
    {
        $definition = $this->repository->getFunctionReference()[$name] ?? null;
        switch (true) {
            case $definition instanceof Closure:
                // reflect closure
                $reflectionFn = ReflectionResource::getFunctionReflection($definition);
                $args = $this->parameterResolver->resolve($reflectionFn, [], 'constructor');
                return $definition(...$args);

            case is_array($definition) && isset($definition[0]) && class_exists($definition[0]):
                return $this->resolveArrayDefinition($definition);

            case is_string($definition) && class_exists($definition):
                $refClass = ReflectionResource::getClassReflection($definition);
                $res = $this->classResolver->resolve($refClass);
                return $res['instance'];

            default:
                return $definition;
        }
    }

    /**
     * Resolves an array definition and returns the resolved value.
     *
     * This method accepts an array where the first element is a class name
     * and the second element is a method name or a boolean. It uses the
     * ClassResolver to resolve the class and either returns the result of
     * the method call if the second element is provided, or the resolved
     * instance if not.
     *
     * @param array $definition An array containing a class name and optionally a method name or boolean.
     * @return mixed The resolved value or instance.
     * @throws ContainerException|ReflectionException
     */
    private function resolveArrayDefinition(array $definition): mixed
    {
        $resolved = $this->classResolver->resolve(...$definition);
        return !empty($definition[1]) ? $resolved['returned'] : $resolved['instance'];
    }
}
