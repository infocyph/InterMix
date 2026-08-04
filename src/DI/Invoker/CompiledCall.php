<?php

declare(strict_types=1);

namespace Infocyph\InterMix\DI\Invoker;

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
     * @param string|null $method Optional method to invoke.
     * @param bool $make Whether to bypass resolved-instance reuse.
     * @return array<string, mixed> Resolved instance and optional method result.
     */
    public function classSettler(
        string|object $class,
        ?string $method = null,
        bool $make = false,
    ): array {
        return $this->dynamicResolver()->classSettler($class, $method, $make);
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
        return $this->definitionResolver->resolve($name);
    }

    private function dynamicResolver(): InjectedCall
    {
        return $this->dynamicResolver ??= new InjectedCall($this->repository);
    }
}
