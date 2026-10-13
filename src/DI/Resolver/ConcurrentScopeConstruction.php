<?php

declare(strict_types=1);

namespace Infocyph\InterMix\DI\Resolver;

use Infocyph\InterMix\DI\Internal\ExecutionContext;
use Infocyph\InterMix\DI\Internal\ExecutionScopeStore;
use Infocyph\InterMix\Exceptions\ContainerException;

/** @internal */
trait ConcurrentScopeConstruction
{
    /**
     * Claim scoped construction on the current carrier and reject conflicting shared construction.
     */
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

    /**
     * Claim singleton construction without allowing conflicting execution carriers.
     */
    public function beginSingletonConstruction(string $id): bool
    {
        $context = ExecutionContext::id() ?? self::ROOT_CONTEXT;
        $owner = $this->singletonConstructing[$id] ?? null;
        if ($owner !== null) {
            if ($owner === $context) {
                return false;
            }

            throw new ContainerException(
                "Singleton service '{$id}' is already being constructed by another execution carrier.",
            );
        }

        $this->singletonConstructing[$id] = $context;

        return true;
    }

    /**
     * Release the current carrier's scoped construction claim after success or failure.
     */
    public function endScopedConstruction(string $scope, string $id): void
    {
        $store = $this->executionScopes;
        $context = $this->activeExecutionContext();
        if ($store instanceof ExecutionScopeStore && $context !== null) {
            $store->endScopedConstruction($context, $scope, $id);
        }
    }

    /**
     * Release the current carrier's singleton construction claim after success or failure.
     */
    public function endSingletonConstruction(string $id): void
    {
        $context = ExecutionContext::id() ?? self::ROOT_CONTEXT;
        if (($this->singletonConstructing[$id] ?? null) === $context) {
            unset($this->singletonConstructing[$id]);
        }
    }

    /**
     * Return the active scope and cached value while preserving null-versus-missing semantics.
     */
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

    /**
     * Detect shared logical state that needs construction ownership enforcement.
     */
    public function requiresScopedConstructionGuard(): bool
    {
        return $this->scopeContextOwner !== null;
    }
}
