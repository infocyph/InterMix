<?php

declare(strict_types=1);

namespace Infocyph\InterMix\DI\Invoker;

use Infocyph\InterMix\DI\Internal\ClassResolution;
use Infocyph\InterMix\DI\Resolver\CompiledDefinitionResolver;
use Infocyph\InterMix\DI\Resolver\Repository;

/**
 * Resolves compiled definitions without constructing the reflection graph.
 * Dynamic operations initialize and delegate to InjectedCall on demand.
 *
 * @internal
 */
final class CompiledCall
{
    private readonly CompiledDefinitionResolver $definitionResolver;

    private ?InjectedCall $dynamicResolver = null;

    /**
     * @param Repository $repository Container state shared with compiled and fallback resolution.
     */
    public function __construct(private readonly Repository $repository)
    {
        $this->definitionResolver = new CompiledDefinitionResolver($this->repository);
        $this->definitionResolver->setResolverFactory(
            fn(): array => $this->dynamicResolver()->reflectionResolvers(),
        );
    }

    /**
     * @param string|object $class Class name or object to resolve dynamically.
     * @param string|false|null $method Method to call, false to construct only, or null for configured behavior.
     * @param bool $make Whether to bypass resolved-instance reuse.
     * @param array<int|string, mixed> $constructorParameters Ephemeral constructor arguments.
     * @param array<int|string, mixed> $methodParameters Ephemeral method arguments.
     */
    public function classSettler(
        string|object $class,
        string|false|null $method = null,
        bool $make = false,
        array $constructorParameters = [],
        array $methodParameters = [],
    ): ClassResolution {
        return $this->dynamicResolver()->classSettler(
            $class,
            $method,
            $make,
            $constructorParameters,
            $methodParameters,
        );
    }

    /**
     * @param callable $closure Callable resolved by the dynamic fallback.
     * @param array<int|string, mixed> $params Explicit callable arguments.
     */
    public function closureSettler(callable $closure, array $params = []): mixed
    {
        return $this->dynamicResolver()->closureSettler($closure, $params);
    }

    /**
     * @param string $name Registered definition identifier.
     */
    public function resolveByDefinition(string $name): mixed
    {
        if (!$this->repository->hasCompiledResolvers()) {
            return $this->dynamicResolver()->resolveByDefinition($name);
        }

        return $this->definitionResolver->resolve($name);
    }

    /** Resolve a warmup miss without another PSR-6 lookup. */
    public function resolveDefinitionForWarmup(string $name): mixed
    {
        if (!$this->repository->hasCompiledResolvers()) {
            return $this->dynamicResolver()->resolveDefinitionForWarmup($name);
        }

        return $this->definitionResolver->resolveForDefinitionCacheWarmup($name);
    }

    private function dynamicResolver(): InjectedCall
    {
        return $this->dynamicResolver ??= new InjectedCall($this->repository);
    }
}
