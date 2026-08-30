<?php

declare(strict_types=1);

namespace Infocyph\InterMix\DI\Internal;

use Infocyph\InterMix\Exceptions\ContainerException;

/** @internal */
final class ExecutionScopeStore
{
    /** @var array<string, ExecutionScopeState> */
    private array $states = [];

    /** @param array<string, mixed> $instances */
    public function enterScope(string $context, string $scope, array $instances = []): void
    {
        $state = $this->states[$context] ??= new ExecutionScopeState();
        if ($scope === $state->currentScope || in_array($scope, $state->scopeStack, true)) {
            throw new ContainerException("Scope \"{$scope}\" is already active.");
        }

        $state->scopeStack[] = $state->currentScope;
        $state->currentScope = $scope;
        if ($instances !== []) {
            $state->scopeSeeds[$scope] = $instances;
        }
    }

    public function findScopeSeed(string $context, string $id, mixed &$value): bool
    {
        $state = $this->states[$context] ?? null;
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

    public function getResolvedScopedEntry(string $context, string $scope, string $id): mixed
    {
        return $this->states[$context]->resolvedScoped[$scope][$id] ?? null;
    }

    public function getScope(string $context): string
    {
        return $this->states[$context]->currentScope ?? 'root';
    }

    public function hasResolvedScoped(string $context, string $scope, string $id): bool
    {
        return array_key_exists($id, $this->states[$context]->resolvedScoped[$scope] ?? []);
    }

    public function hasScopeSeeds(string $context): bool
    {
        return ($this->states[$context]->scopeSeeds ?? []) !== [];
    }

    public function invalidateClass(string $class): void
    {
        foreach ($this->states as $state) {
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
        foreach ($this->states as $state) {
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
        foreach ($this->states as $state) {
            $state->resolvedScoped = [];
        }
    }

    public function isEmpty(): bool
    {
        return $this->states === [];
    }

    public function leaveScope(string $context): void
    {
        $state = $this->states[$context] ??= new ExecutionScopeState();
        $scope = $state->currentScope;
        unset($state->resolvedScoped[$scope], $state->scopeSeeds[$scope]);
        $previous = array_pop($state->scopeStack);
        $state->currentScope = is_string($previous) ? $previous : 'root';

        if ($state->currentScope === 'root'
            && $state->scopeStack === []
            && $state->scopeSeeds === []
            && $state->resolvedScoped === []
        ) {
            unset($this->states[$context]);
        }
    }

    public function resetScope(string $context): void
    {
        unset($this->states[$context]);
    }

    public function setResolvedScoped(string $context, string $scope, string $id, mixed $value): void
    {
        $state = $this->states[$context] ??= new ExecutionScopeState();
        $state->resolvedScoped[$scope][$id] = $value;
    }

    public function setScope(string $context, string $scope): void
    {
        $state = $this->states[$context] ??= new ExecutionScopeState();
        $state->currentScope = $scope;
    }
}
