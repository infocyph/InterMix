<?php

declare(strict_types=1);

namespace Infocyph\InterMix\DI\Resolver;

use Closure;
use Infocyph\InterMix\DI\Support\DirectFactory;
use Infocyph\InterMix\DI\Support\LifetimeEnum;
use Infocyph\InterMix\DI\Support\ReflectionResource;
use Infocyph\InterMix\Exceptions\ContainerException;
use Psr\Cache\InvalidArgumentException;
use ReflectionException;

class DefinitionResolver
{
    private ?ClassResolver $classResolver = null;

    /** @var array<int, string> */
    private array $definitionStack = [];

    /** @var array<string, bool> */
    private array $entriesResolving = [];

    private ?ParameterResolver $parameterResolver = null;

    private ?Closure $resolverInitializer = null;

    /**
     * Constructs a DefinitionResolver instance.
     *
     * @param Repository $repository The Repository providing definitions, classes, functions, and parameters.
     */
    public function __construct(
        private readonly Repository $repository,
    ) {}

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

        $parent = end($this->definitionStack);
        if (is_string($parent) && $parent !== $name && $this->repository->isTracingEnabled()) {
            $this->repository->tracer()->recordDependency($parent, $name, 'definition');
        }

        $this->entriesResolving[$name] = true;
        $this->definitionStack[] = $name;
        if ($this->repository->isTracingEnabled()) {
            $this->repository->tracer()->push("def:$name");
        }

        try {
            return $this->getFromCacheOrResolve($name);
        } finally {
            unset($this->entriesResolving[$name]);
            array_pop($this->definitionStack);
        }
    }

    /**
     * Defer the reflection resolver graph until a definition actually needs it.
     */
    public function setResolverInitializer(Closure $initializer): void
    {
        $this->resolverInitializer = $initializer;
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

    private static function stableHash(string $value): string
    {
        return hash('xxh128', $value);
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
        $lifetime = $this->repository->getDefinitionLifetime($name);
        $environment = $this->repository->getEnvironment() ?? 'default';
        $resolvedKey = $name . '@env:' . $environment;

        // transient / scoped → never cache at this layer
        if ($lifetime !== LifetimeEnum::Singleton) {
            return $this->resolveDefinition($name);
        }

        if ($this->repository->hasResolvedDefinition($resolvedKey)) {
            return $this->repository->getResolvedDefinitionEntry($resolvedKey);
        }

        $value = $this->resolveSingletonDefinition($name, $resolvedKey);
        $this->repository->setResolvedDefinition($resolvedKey, $value);

        return $value;
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
     * @param array<int, mixed> $definition An array containing a class name and optionally a method name.
     * @return mixed The resolved value or instance.
     * @throws ContainerException|ReflectionException
     */
    private function resolveArrayDefinition(array $definition): mixed
    {
        [$classResolver] = $this->resolvers();

        $class = $definition[0] ?? null;
        if (!is_string($class)) {
            return $definition;
        }
        $method = isset($definition[1]) && is_string($definition[1]) ? $definition[1] : null;
        $resolved = $classResolver->resolve(
            ReflectionResource::getClassReflection($class),
            null,
            $method,
            true,
        );

        return $method !== null ? $resolved['returned'] : $resolved['instance'];
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
        $compiled = $this->repository->getCompiledResolver($name);
        if (is_callable($compiled)) {
            return $compiled($this->repository->container());
        }

        $definition = $this->repository->getFunctionDefinition($name);
        switch (true) {
            case $definition instanceof DirectFactory:
                return $definition->resolve();

            case $definition instanceof Closure:
                [, $parameterResolver] = $this->resolvers();

                // reflect closure
                $reflectionFn = ReflectionResource::getFunctionReflection($definition);
                $args = $parameterResolver->resolve($reflectionFn, [], 'constructor');

                return $definition(...$args);

            case is_array($definition) && isset($definition[0]) && is_string($definition[0]) && class_exists($definition[0]):
                if ($this->repository->isTracingEnabled()) {
                    $this->repository->tracer()->recordDependency($name, $definition[0], 'definition-class');
                }

                return $this->resolveArrayDefinition(array_values($definition));

            case is_string($definition) && class_exists($definition):
                [$classResolver] = $this->resolvers();

                if ($this->repository->isTracingEnabled()) {
                    $this->repository->tracer()->recordDependency($name, $definition, 'definition-class');
                }
                $refClass = ReflectionResource::getClassReflection($definition);
                $res = $classResolver->resolve($refClass, make: true);

                return $res['instance'];

            default:
                return $definition;
        }
    }

    /**
     * @return array{ClassResolver, ParameterResolver}
     */
    private function resolvers(): array
    {
        if ($this->classResolver instanceof ClassResolver && $this->parameterResolver instanceof ParameterResolver) {
            return [$this->classResolver, $this->parameterResolver];
        }

        ($this->resolverInitializer ?? throw new ContainerException('Definition resolver graph is not initialized.'))();

        return [
            $this->classResolver ?? throw new ContainerException('Class resolver is unavailable.'),
            $this->parameterResolver ?? throw new ContainerException('Parameter resolver is unavailable.'),
        ];
    }

    private function resolveSingletonDefinition(string $name, string $resolvedKey): mixed
    {
        $cacheAdapter = $this->repository->getCacheAdapter();
        if ($cacheAdapter === null) {
            return $this->resolveDefinition($name);
        }

        $cacheKey = $this->repository->makeCacheKey('def:' . self::stableHash($resolvedKey));
        $item = $cacheAdapter->getItem($cacheKey);
        if ($item->isHit()) {
            $cachedValue = $item->get();
            if ($this->repository->shouldPersistDefinitionValue($cachedValue)) {
                return $cachedValue;
            }
        }

        $value = $this->resolveDefinition($name);
        if ($this->repository->shouldPersistDefinitionValue($value)) {
            $item->set($value);
            $cacheAdapter->save($item);
        }

        return $value;
    }
}
