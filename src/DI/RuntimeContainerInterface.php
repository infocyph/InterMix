<?php

declare(strict_types=1);

namespace Infocyph\InterMix\DI;

use Psr\Container\ContainerInterface;

interface RuntimeContainerInterface extends ContainerInterface
{
    public function captureScopeContext(): ScopeContext;

    /** @param array<int|string, mixed> $arguments */
    public function invoke(callable $callable, array $arguments = []): mixed;

    /**
     * @param class-string $class
     * @param array<int|string, mixed> $arguments
     */
    public function make(string $class, array $arguments = []): object;

    public function resetCurrentExecutionScope(): void;

    /** @return iterable<string, mixed> */
    public function tagged(string $tag): iterable;

    /** @param array<string, mixed> $instances */
    public function withinScope(string $scope, callable $callback, array $instances = []): mixed;

    public function withinScopeContext(ScopeContext $scopeContext, callable $callback): mixed;
}
