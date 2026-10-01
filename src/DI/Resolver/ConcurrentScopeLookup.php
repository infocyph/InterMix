<?php

declare(strict_types=1);

namespace Infocyph\InterMix\DI\Resolver;

use Infocyph\InterMix\DI\Internal\ExecutionScopeStore;

/** @internal */
trait ConcurrentScopeLookup
{
    /** @internal */
    public function findScopeSeed(string $id, mixed &$value): bool
    {
        $store = $this->executionScopes;
        if ($store instanceof ExecutionScopeStore) {
            $context = $this->activeExecutionContext();
            if ($context !== null) {
                return $store->findScopeSeed($context, $id, $value);
            }
        }

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

    /** @internal */
    public function getResolvedScopedEntry(string $scope, string $id): mixed
    {
        $store = $this->executionScopes;
        if ($store instanceof ExecutionScopeStore) {
            $context = $this->activeExecutionContext();
            if ($context !== null) {
                return $store->getResolvedScopedEntry($context, $scope, $id);
            }
        }

        return $this->resolvedScoped[$scope][$id] ?? null;
    }

    public function getScope(): string
    {
        $store = $this->executionScopes;
        if ($store instanceof ExecutionScopeStore) {
            $context = $this->activeExecutionContext();
            if ($context !== null) {
                return $store->getScope($context);
            }
        }

        return $this->currentScope;
    }

    /** @internal */
    public function hasResolvedScoped(string $scope, string $id): bool
    {
        $store = $this->executionScopes;
        if ($store instanceof ExecutionScopeStore) {
            $context = $this->activeExecutionContext();
            if ($context !== null) {
                return $store->hasResolvedScoped($context, $scope, $id);
            }
        }

        return array_key_exists($id, $this->resolvedScoped[$scope] ?? []);
    }

    /** @internal */
    public function hasScopeSeeds(): bool
    {
        $store = $this->executionScopes;
        if ($store instanceof ExecutionScopeStore) {
            $context = $this->activeExecutionContext();
            if ($context !== null) {
                return $store->hasScopeSeeds($context);
            }
        }

        return $this->scopeSeeds !== [];
    }
}
