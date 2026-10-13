<?php

declare(strict_types=1);

namespace Infocyph\InterMix\DI\Resolver;

use Infocyph\InterMix\DI\Internal\ExecutionScopeStore;

/** @internal */
trait ConcurrentScopeLookup
{
    /**
     * Read the current carrier's seed and return presence separately from a possibly null value.
     *
     * @internal
     */
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

    /**
     * Read the named scope's cached value on the current carrier.
     *
     * @internal
     */
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

    /**
     * Expose the current carrier's active scope name, defaulting to root.
     */
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

    /**
     * Check current-carrier cache presence without treating null values as absent.
     *
     * @internal
     */
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

    /**
     * Check whether the current carrier retains supplied scope seeds.
     *
     * @internal
     */
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
