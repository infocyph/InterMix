<?php

declare(strict_types=1);

namespace Infocyph\InterMix\DI\Resolver;

use Closure;
use Infocyph\InterMix\DI\Support\DirectFactory;
use Infocyph\InterMix\DI\Support\FactoryDefinition;
use Infocyph\InterMix\DI\Support\LifetimeEnum;
use Infocyph\InterMix\Exceptions\ContainerException;
use Infocyph\InterMix\Internal\ReflectionResource;
use Psr\Cache\CacheItemInterface;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Cache\InvalidArgumentException;
use ReflectionException;
use Throwable;

class DefinitionResolver
{
    private ?ClassResolver $classResolver = null;

    /** @var array<int, string> */
    private array $definitionStack = [];

    /** @var array<string, bool> */
    private array $entriesResolving = [];

    private ?ParameterResolver $parameterResolver = null;

    /** @var (Closure(): array{ClassResolver, ParameterResolver})|null */
    private ?Closure $resolverFactory = null;

    public function __construct(protected readonly Repository $repository) {}

    /** @throws ContainerException|InvalidArgumentException|ReflectionException */
    public function resolve(string $name): mixed
    {
        return $this->resolveTracked($name, false);
    }

    public function resolveForDefinitionCacheWarmup(string $name): mixed
    {
        return $this->resolveTracked($name, true);
    }

    /** @param Closure(): array{ClassResolver, ParameterResolver} $resolverFactory */
    public function setResolverFactory(Closure $resolverFactory): void
    {
        $this->resolverFactory = $resolverFactory;
    }

    public function setResolverInstance(
        ClassResolver $classResolver,
        ParameterResolver $parameterResolver,
    ): void {
        $this->classResolver = $classResolver;
        $this->parameterResolver = $parameterResolver;
    }

    /** @throws ContainerException|ReflectionException|InvalidArgumentException */
    protected function resolveDefinition(string $name): mixed
    {
        $definition = $this->repository->getFunctionDefinition($name);

        return match (true) {
            $definition instanceof DirectFactory => $definition->resolve(),
            $definition instanceof Closure => $this->resolveClosure($definition),
            $definition instanceof FactoryDefinition => $definition->resolve($this->repository->container()),
            is_array($definition)
                && isset($definition[0])
                && is_string($definition[0])
                && class_exists($definition[0]) => $this->resolveArrayDefinitionTracked($name, $definition),
            is_string($definition) && class_exists($definition) => $this->resolveClassDefinition($name, $definition),
            default => $definition,
        };
    }

    /**
     * Runtime lifetime ownership lives in InvocationManager::get(). This layer
     * only decides whether a safe singleton value may use the optional external
     * definition cache; it no longer maintains a second in-process singleton map.
     */
    private function getFromCacheOrResolve(string $name, bool $skipExternalCache = false): mixed
    {
        if ($this->repository->getDefinitionLifetime($name) !== LifetimeEnum::Singleton
            || $skipExternalCache
        ) {
            return $this->resolveDefinition($name);
        }

        return $this->resolveSingletonDefinition($name);
    }

    private function ignoreDefinitionCacheFailure(Throwable $failure): void
    {
        if (!$this->repository->isDefinitionCacheFailOpen()) {
            throw $failure;
        }
    }

    private function persistDefinition(
        CacheItemPoolInterface $cache,
        CacheItemInterface $item,
        mixed $value,
    ): void {
        try {
            $item->set($value);
            if (!$cache->save($item)) {
                throw new ContainerException('Definition cache write failed.');
            }
        } catch (Throwable $failure) {
            $this->ignoreDefinitionCacheFailure($failure);
        }
    }

    /** @return array{CacheItemInterface, bool, mixed} */
    private function readCachedDefinition(CacheItemPoolInterface $cache, string $key): array
    {
        $item = $cache->getItem($key);
        $hit = $item->isHit();
        $value = $hit ? $item->get() : null;
        $hit = $hit && $this->repository->shouldPersistDefinitionValue($value);

        return [$item, $hit, $value];
    }

    /** @param array<int, mixed> $definition */
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

        return $method !== null ? $resolved->returned : $resolved->instance;
    }

    /** @param array<int|string, mixed> $definition */
    private function resolveArrayDefinitionTracked(string $name, array $definition): mixed
    {
        $class = $definition[0] ?? null;
        if ($this->repository->isTracingEnabled() && is_string($class)) {
            $this->repository->tracer()->recordDependency($name, $class, 'definition-class');
        }

        return $this->resolveArrayDefinition(array_values($definition));
    }

    private function resolveClassDefinition(string $name, string $definition): mixed
    {
        [$classResolver] = $this->resolvers();
        if ($this->repository->isTracingEnabled()) {
            $this->repository->tracer()->recordDependency($name, $definition, 'definition-class');
        }

        return $classResolver->resolve(
            ReflectionResource::getClassReflection($definition),
            make: true,
        )->instance;
    }

    private function resolveClosure(Closure $definition): mixed
    {
        [, $parameterResolver] = $this->resolvers();
        $reflectionFn = ReflectionResource::getFunctionReflection($definition);

        return $definition(...$parameterResolver->resolve($reflectionFn, [], 'constructor'));
    }

    /** @return array{ClassResolver, ParameterResolver} */
    private function resolvers(): array
    {
        $classResolver = $this->classResolver;
        $parameterResolver = $this->parameterResolver;
        if (!$classResolver instanceof ClassResolver || !$parameterResolver instanceof ParameterResolver) {
            $factory = $this->resolverFactory
                ?? throw new ContainerException('Reflection resolver factory is unavailable.');
            [$classResolver, $parameterResolver] = $factory();
            $this->setResolverInstance($classResolver, $parameterResolver);
        }

        return [$classResolver, $parameterResolver];
    }

    private function resolveSingletonDefinition(string $name): mixed
    {
        $definitionCache = $this->repository->getDefinitionCache();
        if ($definitionCache === null) {
            return $this->resolveDefinition($name);
        }

        $cacheKey = $this->repository->makeDefinitionCacheKey($name);

        try {
            [$item, $hit, $cachedValue] = $this->readCachedDefinition($definitionCache, $cacheKey);
            if ($hit) {
                return $cachedValue;
            }
        } catch (Throwable $failure) {
            $this->ignoreDefinitionCacheFailure($failure);

            return $this->resolveDefinition($name);
        }

        $value = $this->resolveDefinition($name);
        if ($this->repository->shouldPersistDefinitionValue($value)) {
            $this->persistDefinition($definitionCache, $item, $value);
        }

        return $value;
    }

    private function resolveTracked(string $name, bool $skipExternalCache): mixed
    {
        if (isset($this->entriesResolving[$name])) {
            throw new ContainerException("Circular dependency for definition '$name'.");
        }

        $tracing = $this->repository->isTracingEnabled();
        if ($tracing) {
            $parent = end($this->definitionStack);
            if (is_string($parent) && $parent !== $name) {
                $this->repository->tracer()->recordDependency($parent, $name, 'definition');
            }
            $this->definitionStack[] = $name;
            $this->repository->tracer()->push("def:$name");
        }

        $this->entriesResolving[$name] = true;

        try {
            $resolved = $this->getFromCacheOrResolve($name, $skipExternalCache);
            $this->repository->markResolved($name);

            return $resolved;
        } finally {
            unset($this->entriesResolving[$name]);
            if ($tracing) {
                array_pop($this->definitionStack);
            }
        }
    }
}
