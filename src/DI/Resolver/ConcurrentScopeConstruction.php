<?php

declare(strict_types=1);

namespace Infocyph\InterMix\DI\Resolver;

use Infocyph\InterMix\DI\Internal\ExecutionScopeStore;

/** @internal */
trait ConcurrentScopeConstruction
{
    public function beginScopedConstruction(string $scope, string $id): bool
    {
        if ($this->scopeContextOwner === null) {
            return false;
        }

        $store = $this->executionScopes;
        $context = $this->activeExecutionContext();
        if (!$store instanceof ExecutionScopeStore || $context === null) {
            return false;
        }

        return $store->beginScopedConstruction($context, $scope, $id);
    }

    public function endScopedConstruction(string $scope, string $id): void
    {
        $store = $this->executionScopes;
        $context = $this->activeExecutionContext();
        if ($store instanceof ExecutionScopeStore && $context !== null) {
            $store->endScopedConstruction($context, $scope, $id);
        }
    }

    public function findCurrentResolvedScoped(string $id, string &$scope, mixed &$value): bool
    {
        $store = $this->executionScopes;
        if ($store instanceof ExecutionScopeStore) {
            $context = $this->activeExecutionContext();
            if ($context !== null) {
                return $store->findCurrentResolvedScoped($context, $id, $scope, $value);
            }
        }

        $scope = $this->currentScope;
        if (!array_key_exists($id, $this->resolvedScoped[$scope] ?? [])) {
            return false;
        }
        $value = $this->resolvedScoped[$scope][$id];

        return true;
    }

    public function requiresScopedConstructionGuard(): bool
    {
        return $this->scopeContextOwner !== null;
    }
}
