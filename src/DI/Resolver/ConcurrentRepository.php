<?php

declare(strict_types=1);

namespace Infocyph\InterMix\DI\Resolver;

use Infocyph\InterMix\DI\Internal\ExecutionContext;
use Infocyph\InterMix\DI\Internal\ExecutionScopeState;
use Infocyph\InterMix\Exceptions\ContainerException;

/** @internal */
final class ConcurrentRepository extends Repository
{
    private bool $contextScopesActive = false;

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
        $this->contextScopesActive = true;
    }

    /** @internal */
    public function findScopeSeed(string $id, mixed &$value): bool
    {
        if (!$this->contextScopesActive) {
            return parent::findScopeSeed($id, $value);
        }

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
        if (!$this->contextScopesActive) {
            return parent::getResolvedScopedEntry($scope, $id);
        }

        $context = ExecutionContext::id();
        if ($context === null) {
            return parent::getResolvedScopedEntry($scope, $id);
        }

        $state = $this->executionScopes[$context] ?? null;

        return $state instanceof ExecutionScopeState
            ? ($state->resolvedScoped[$scope][$id] ?? null)
            : null;
    }

    public function getScope(): string
    {
        if (!$this->contextScopesActive) {
            return parent::getScope();
        }

        $context = ExecutionContext::id();
        if ($context === null) {
            return parent::getScope();
        }

        return $this->executionScopes[$context]->currentScope ?? 'root';
    }

    /** @internal */
    public function hasResolvedScoped(string $scope, string $id): bool
    {
        if (!$this->contextScopesActive) {
            return parent::hasResolvedScoped($scope, $id);
        }

        $context = ExecutionContext::id();
        if ($context === null) {
            return parent::hasResolvedScoped($scope, $id);
        }

        $state = $this->executionScopes[$context] ?? null;

        return $state instanceof ExecutionScopeState
            && array_key_exists($id, $state->resolvedScoped[$scope] ?? []);
    }

    /** @internal */
    public function hasScopeSeeds(): bool
    {
        if (!$this->contextScopesActive) {
            return parent::hasScopeSeeds();
        }

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
        if (!$this->contextScopesActive) {
            parent::leaveScope();

            return;
        }

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
        $this->contextScopesActive = $this->executionScopes !== [];
    }

    public function onScopeLeave(string $scope, callable $hook): void
    {
        parent::onScopeLeave($scope, $hook);
        $this->executionScopeLeaveHooks[$scope][] = $hook;
    }

    public function resetScope(): void
    {
        if (!$this->contextScopesActive) {
            parent::resetScope();

            return;
        }

        $context = ExecutionContext::id();
        if ($context === null) {
            parent::resetScope();
            $this->executionScopes = [];
            $this->contextScopesActive = false;

            return;
        }

        unset($this->executionScopes[$context]);
        $this->contextScopesActive = $this->executionScopes !== [];
    }

    public function setEnvironment(string $env): void
    {
        if (parent::getEnvironment() === $env) {
            return;
        }

        parent::setEnvironment($env);
        $this->executionScopes = [];
        $this->contextScopesActive = false;
    }

    public function setResolvedScoped(string $scope, string $id, mixed $value): void
    {
        if (!$this->contextScopesActive) {
            parent::setResolvedScoped($scope, $id, $value);

            return;
        }

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
        if (!$this->contextScopesActive) {
            parent::setScope($scope);

            return;
        }

        $context = ExecutionContext::id();
        if ($context === null) {
            parent::setScope($scope);

            return;
        }

        $state = $this->executionScopes[$context] ??= new ExecutionScopeState();
        $state->currentScope = $scope;
    }
}
