<?php

declare(strict_types=1);

namespace Infocyph\InterMix\DI\Resolver;

use Infocyph\InterMix\DI\Internal\ExecutionContext;
use Infocyph\InterMix\DI\Internal\ExecutionScopeState;
use Infocyph\InterMix\Exceptions\ContainerException;

/** @internal */
final class ConcurrentRepository extends Repository
{
    /** @var array<string, array<int, callable(string, \Infocyph\InterMix\DI\Container): void>> */
    private array $executionScopeLeaveHooks = [];

    /** @var array<string, ExecutionScopeState> */
    private array $executionScopes = [];

    /** @param array<string, mixed> $instances */
    public function enterScope(string $scope, array $instances = []): void
    {
        $context = ExecutionContext::id();
        if ($context === null) {
            parent::enterScope($scope, $instances);

            return;
        }

        $state = $this->executionScopes[$context] ??= new ExecutionScopeState();
        if ($scope === $state->currentScope || in_array($scope, $state->scopeStack, true)) {
            throw new ContainerException("Scope \"{$scope}\" is already active.");
        }

        $state->scopeStack[] = $state->currentScope;
        $state->currentScope = $scope;
        if ($instances !== []) {
            $state->scopeSeeds[$scope] = $instances;
        }
    }

    /** @internal */
    public function findScopeSeed(string $id, mixed &$value): bool
    {
        $context = ExecutionContext::id();
        if ($context === null) {
            return parent::findScopeSeed($id, $value);
        }

        $state = $this->executionScopes[$context] ?? null;
        if (!$state instanceof ExecutionScopeState || $state->scopeSeeds === []) {
            return false;
        }

        $seeds = $state->scopeSeeds[$state->currentScope] ?? null;
        if (!is_array($seeds) || !array_key_exists($id, $seeds)) {
            return false;
        }

        $value = $seeds[$id];

        return true;
    }

    /** @internal */
    public function getResolvedScopedEntry(string $scope, string $id): mixed
    {
        $context = ExecutionContext::id();
        if ($context === null) {
            return parent::getResolvedScopedEntry($scope, $id);
        }

        return $this->executionScopes[$context]->resolvedScoped[$scope][$id] ?? null;
    }

    public function getScope(): string
    {
        $context = ExecutionContext::id();
        if ($context === null) {
            return parent::getScope();
        }

        return $this->executionScopes[$context]->currentScope ?? 'root';
    }

    /** @internal */
    public function hasResolvedScoped(string $scope, string $id): bool
    {
        $context = ExecutionContext::id();
        if ($context === null) {
            return parent::hasResolvedScoped($scope, $id);
        }

        return array_key_exists($id, $this->executionScopes[$context]->resolvedScoped[$scope] ?? []);
    }

    /** @internal */
    public function hasScopeSeeds(): bool
    {
        $context = ExecutionContext::id();
        if ($context === null) {
            return parent::hasScopeSeeds();
        }

        return ($this->executionScopes[$context]->scopeSeeds ?? []) !== [];
    }

    public function invalidateClass(string $class): void
    {
        parent::invalidateClass($class);
        foreach ($this->executionScopes as $state) {
            foreach (array_keys($state->resolvedScoped) as $scope) {
                unset($state->resolvedScoped[$scope][$class]);
            }
        }
    }

    public function invalidateDefinition(string $id): void
    {
        parent::invalidateDefinition($id);
        foreach ($this->executionScopes as $state) {
            foreach (array_keys($state->resolvedScoped) as $scope) {
                unset($state->resolvedScoped[$scope][$id]);
                if ($state->resolvedScoped[$scope] === []) {
                    unset($state->resolvedScoped[$scope]);
                }
            }
        }
    }

    public function invalidateResolutionConfiguration(): void
    {
        parent::invalidateResolutionConfiguration();
        foreach ($this->executionScopes as $state) {
            $state->resolvedScoped = [];
        }
    }

    public function leaveScope(): void
    {
        $context = ExecutionContext::id();
        if ($context === null) {
            parent::leaveScope();

            return;
        }

        $state = $this->executionScopes[$context] ??= new ExecutionScopeState();
        $scope = $state->currentScope;
        foreach ($this->executionScopeLeaveHooks[$scope] ?? [] as $hook) {
            $hook($scope, $this->container());
        }

        unset($state->resolvedScoped[$scope], $state->scopeSeeds[$scope]);
        $previous = array_pop($state->scopeStack);
        $state->currentScope = is_string($previous) ? $previous : 'root';

        if ($state->currentScope === 'root'
            && $state->scopeStack === []
            && $state->scopeSeeds === []
            && $state->resolvedScoped === []
        ) {
            unset($this->executionScopes[$context]);
        }
    }

    public function onScopeLeave(string $scope, callable $hook): void
    {
        parent::onScopeLeave($scope, $hook);
        $this->executionScopeLeaveHooks[$scope][] = $hook;
    }

    public function resetScope(): void
    {
        $context = ExecutionContext::id();
        if ($context === null) {
            parent::resetScope();
            $this->executionScopes = [];

            return;
        }

        unset($this->executionScopes[$context]);
    }

    public function setEnvironment(string $env): void
    {
        parent::setEnvironment($env);
        $this->executionScopes = [];
    }

    public function setResolvedScoped(string $scope, string $id, mixed $value): void
    {
        $context = ExecutionContext::id();
        if ($context === null) {
            parent::setResolvedScoped($scope, $id, $value);

            return;
        }

        $state = $this->executionScopes[$context] ??= new ExecutionScopeState();
        $state->resolvedScoped[$scope][$id] = $value;
    }

    public function setScope(string $scope): void
    {
        $context = ExecutionContext::id();
        if ($context === null) {
            parent::setScope($scope);

            return;
        }

        $state = $this->executionScopes[$context] ??= new ExecutionScopeState();
        $state->currentScope = $scope;
    }
}
