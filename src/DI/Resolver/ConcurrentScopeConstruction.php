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

    public function requiresScopedConstructionGuard(): bool
    {
        return $this->scopeContextOwner !== null;
    }
}
