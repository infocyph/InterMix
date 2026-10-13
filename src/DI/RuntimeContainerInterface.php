<?php

declare(strict_types=1);

namespace Infocyph\InterMix\DI;

use Psr\Container\ContainerInterface;

interface RuntimeContainerInterface extends ContainerInterface
{
    /**
     * Capture an active, container-owned scope for propagation between in-process carriers.
     */
    public function captureScopeContext(): ScopeContext;

    /**
     * Resolve missing callable arguments through the container while honoring explicit arguments.
     *
     * @param array<int|string, mixed> $arguments
     */
    public function invoke(callable $callable, array $arguments = []): mixed;

    /**
     * Construct a fresh instance without reusing the service lifetime cache.
     *
     * @param class-string $class
     * @param array<int|string, mixed> $arguments
     */
    public function make(string $class, array $arguments = []): object;

    /**
     * Release the current carrier's scope state and run its cleanup hooks.
     */
    public function resetCurrentExecutionScope(): void;

    /**
     * Resolve tagged entries lazily; an iterator captured in a scope must remain in that scope.
     *
     * @return iterable<string, mixed>
     */
    public function tagged(string $tag): iterable;

    /**
     * Run work with validated scoped seeds and release the scope even when work fails.
     *
     * @param array<string, mixed> $instances
     */
    public function withinScope(string $scope, callable $callback, array $instances = []): mixed;

    /**
     * Attach an active scope handle for the callback and detach it afterward.
     */
    public function withinScopeContext(ScopeContext $scopeContext, callable $callback): mixed;
}
