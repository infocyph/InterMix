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

    private string $currentScope = 'root';

    /** @var array<string, ExecutionScopeState> */
    private array $executionScopes = [];

    /** @var array<string, array<string, mixed>> */
    private array $resolvedScoped = [];

    /** @var array<string, array<int, callable(string, \Infocyph\InterMix\DI\Container): void>> */
    private array $scopeLeaveHooks = [];

    /** @var array<string, array<string, mixed>> */
    private array $scopeSeeds = [];

    /** @var array<int, string> */
    private array $scopeStack = [];

    /** @param array<string, mixed> $instances */
    public function enterScope(string $scope, array $instances = []): void
    {
        $context = ExecutionContext::id();
        if ($context === null) {
            if ($scope === $this->currentScope || in_array($scope, $this->scopeStack, true)) {
                throw new ContainerException("Scope \"{$scope}\" is already active.");
            }

            $this->scopeStack[] = $this->currentScope;
            $this->currentScope = $scope;
            if ($instances !== []) {
                $this->scopeSeeds[$scope] = $instances;
            }

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
            if ($this->scopeSeeds === []) {
                return false;
            }

            $seeds = $this->scopeSeeds[$this->currentScope] ?? null;
            if (!is_array($seeds) || !array_key_exists($id, $seeds)) {
                return false;
            }

            $value = $seeds[$id];

            return true;
        }

        $context = ExecutionContext::id();
        if ($context === null) {
            if ($this->scopeSeeds === []) {
                return false;
            }

            $seeds = $this->scopeSeeds[$this->currentScope] ?? null;
            if (!is_array($seeds) || !array_key_exists($id, $seeds)) {
                return false;
            }

            $value = $seeds[$id];

            return true;
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
            return $this->resolvedScoped[$scope][$id] ?? null;
        }

        $context = ExecutionContext::id();
        if ($context === null) {
            return $this->resolvedScoped[$scope][$id] ?? null;
        }

        $state = $this->executionScopes[$context] ?? null;

        return $state instanceof ExecutionScopeState
            ? ($state->resolvedScoped[$scope][$id] ?? null)
            : null;
    }

    public function getScope(): string
    {
        if (!$this->contextScopesActive) {
            return $this->currentScope;
        }

        $context = ExecutionContext::id();
        if ($context === null) {
            return $this->currentScope;
        }

        return $this->executionScopes[$context]->currentScope ?? 'root';
    }

    /** @internal */
    public function hasResolvedScoped(string $scope, string $id): bool
    {
        if (!$this->contextScopesActive) {
            return array_key_exists($id, $this->resolvedScoped[$scope] ?? []);
        }

        $context = ExecutionContext::id();
        if ($context === null) {
            return array_key_exists($id, $this->resolvedScoped[$scope] ?? []);
        }

        $state = $this->executionScopes[$context] ?? null;

        return $state instanceof ExecutionScopeState
            && array_key_exists($id, $state->resolvedScoped[$scope] ?? []);
    }

    /** @internal */
    public function hasScopeSeeds(): bool
    {
        if (!$this->contextScopesActive) {
            return $this->scopeSeeds !== [];
        }

        $context = ExecutionContext::id();
        if ($context === null) {
            return $this->scopeSeeds !== [];
        }

        return ($this->executionScopes[$context]->scopeSeeds ?? []) !== [];
    }

    public function invalidateClass(string $class): void
    {
        parent::invalidateClass($class);
        foreach (array_keys($this->resolvedScoped) as $scope) {
            unset($this->resolvedScoped[$scope][$class]);
            if ($this->resolvedScoped[$scope] === []) {
                unset($this->resolvedScoped[$scope]);
            }
        }
        foreach ($this->executionScopes as $state) {
            foreach (array_keys($state->resolvedScoped) as $scope) {
                unset($state->resolvedScoped[$scope][$class]);
                if ($state->resolvedScoped[$scope] === []) {
                    unset($state->resolvedScoped[$scope]);
                }
            }
        }
    }

    public function invalidateDefinition(string $id): void
    {
        parent::invalidateDefinition($id);
        foreach (array_keys($this->resolvedScoped) as $scope) {
            unset($this->resolvedScoped[$scope][$id]);
            if ($this->resolvedScoped[$scope] === []) {
                unset($this->resolvedScoped[$scope]);
            }
        }
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
        $this->resolvedScoped = [];
        foreach ($this->executionScopes as $state) {
            $state->resolvedScoped = [];
        }
    }

    public function leaveScope(): void
    {
        if (!$this->contextScopesActive) {
            $scope = $this->currentScope;
            foreach ($this->scopeLeaveHooks[$scope] ?? [] as $hook) {
                $hook($scope, $this->container());
            }

            unset($this->resolvedScoped[$scope], $this->scopeSeeds[$scope]);
            $previous = array_pop($this->scopeStack);
            $this->currentScope = is_string($previous) ? $previous : 'root';

            return;
        }

        $context = ExecutionContext::id();
        if ($context === null) {
            $scope = $this->currentScope;
            foreach ($this->scopeLeaveHooks[$scope] ?? [] as $hook) {
                $hook($scope, $this->container());
            }

            unset($this->resolvedScoped[$scope], $this->scopeSeeds[$scope]);
            $previous = array_pop($this->scopeStack);
            $this->currentScope = is_string($previous) ? $previous : 'root';

            return;
        }

        $state = $this->executionScopes[$context] ??= new ExecutionScopeState();
        $scope = $state->currentScope;
        foreach ($this->scopeLeaveHooks[$scope] ?? [] as $hook) {
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
        $this->scopeLeaveHooks[$scope][] = $hook;
    }

    public function resetScope(): void
    {
        if (!$this->contextScopesActive) {
            $this->resolvedScoped = [];
            $this->scopeStack = [];
            $this->scopeSeeds = [];
            $this->currentScope = 'root';

            return;
        }

        $context = ExecutionContext::id();
        if ($context === null) {
            $this->resolvedScoped = [];
            $this->scopeStack = [];
            $this->scopeSeeds = [];
            $this->currentScope = 'root';
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
        $this->resolvedScoped = [];
        $this->scopeStack = [];
        $this->scopeSeeds = [];
        $this->currentScope = 'root';
        $this->executionScopes = [];
        $this->contextScopesActive = false;
    }

    public function setResolvedScoped(string $scope, string $id, mixed $value): void
    {
        if (!$this->contextScopesActive) {
            $this->resolvedScoped[$scope][$id] = $value;

            return;
        }

        $context = ExecutionContext::id();
        if ($context === null) {
            $this->resolvedScoped[$scope][$id] = $value;

            return;
        }

        $state = $this->executionScopes[$context] ??= new ExecutionScopeState();
        $state->resolvedScoped[$scope][$id] = $value;
    }

    public function setScope(string $scope): void
    {
        if (!$this->contextScopesActive) {
            $this->currentScope = $scope;

            return;
        }

        $context = ExecutionContext::id();
        if ($context === null) {
            $this->currentScope = $scope;

            return;
        }

        $state = $this->executionScopes[$context] ??= new ExecutionScopeState();
        $state->currentScope = $scope;
    }
}
