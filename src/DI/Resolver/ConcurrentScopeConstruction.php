<?php

declare(strict_types=1);

namespace Infocyph\InterMix\DI\Resolver;

use Infocyph\InterMix\DI\Internal\ExecutionContext;
use Infocyph\InterMix\DI\Internal\ExecutionScopeStore;
use Infocyph\InterMix\Exceptions\ContainerException;

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

    public function endScopedConstruction(string $scope, string $id): void
    {
        $store = $this->executionScopes;
        $context = $this->activeExecutionContext();
        if ($store instanceof ExecutionScopeStore && $context !== null) {
            $store->endScopedConstruction($context, $scope, $id);
        }
    }

    public function endSingletonConstruction(string $id): void
    {
        $context = ExecutionContext::id() ?? self::ROOT_CONTEXT;
        if (($this->singletonConstructing[$id] ?? null) === $context) {
            unset($this->singletonConstructing[$id]);
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
