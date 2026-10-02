<?php

declare(strict_types=1);

namespace Infocyph\InterMix\DI\Internal;

use Infocyph\InterMix\Exceptions\ContainerException;
use Infocyph\InterMix\Exceptions\ScopeCleanupException;

/** @internal */
trait ExecutionScopeMaintenance
{
    public function hasConcurrentActivity(?string $currentContext): bool
    {
        foreach ($this->states as $context => $state) {
            if ($context !== $currentContext || $state->attachedScope instanceof LogicalScopeState) {
                return true;
            }

            for ($scope = $state->logicalCurrent; $scope instanceof LogicalScopeState; $scope = $scope->parent) {
                if ($scope->attachments > 0) {
                    return true;
                }
            }
        }

        return false;
    }

    public function invalidateClass(string $class): void
    {
        $this->invalidateResolvedEntry($class);
    }

    public function invalidateDefinition(string $id): void
    {
        $this->invalidateResolvedEntry($id);
    }

    public function invalidateResolutionConfiguration(): void
    {
        foreach ($this->states as $state) {
            $state->resolvedScoped = [];
        }
        $this->walkUniqueFrames(static function (LogicalScopeState $scope): void {
            $scope->resolvedScoped = [];
            $scope->constructing = [];
        });
    }

    /**
     * @param callable(): void $leaveScope
     * @return array{failures: list<\Throwable>, count: int}
     */
    public function resetContext(string $context, callable $leaveScope): array
    {
        $failures = [];
        $failureCount = 0;

        if ($this->isAttached($context)) {
            while ($this->hasNestedScope($context)) {
                try {
                    $leaveScope();
                } catch (ScopeCleanupException $failure) {
                    $this->appendCleanupFailure($failure, $failures, $failureCount);
                }
            }
            $this->detachCurrentScopeContext($context);

            return ['failures' => $failures, 'count' => $failureCount];
        }

        while ($this->hasState($context)) {
            try {
                $leaveScope();
            } catch (ScopeCleanupException $failure) {
                $this->appendCleanupFailure($failure, $failures, $failureCount);
            }
        }

        return ['failures' => $failures, 'count' => $failureCount];
    }

    public function scopeForLeave(string $context): string
    {
        $state = $this->states[$context] ?? null;
        if (!$state instanceof ExecutionScopeState) {
            return 'root';
        }

        $scope = $state->logicalCurrent;
        if (!$scope instanceof LogicalScopeState) {
            return $state->currentScope;
        }
        if ($state->attachedScope === $scope) {
            throw new ContainerException('Cannot leave an attached scope context; detach it instead.');
        }
        if ($scope->attachments > 0) {
            $scope->draining = true;

            throw new ContainerException('Cannot leave a scope while child execution carriers are still attached.');
        }

        return $scope->name;
    }

    /**
     * @param list<\Throwable> $failures
     */
    private function appendCleanupFailure(
        ScopeCleanupException $failure,
        array &$failures,
        int &$failureCount,
    ): void {
        $failureCount += $failure->cleanupFailureCount;
        foreach ($failure->cleanupFailures as $cleanupFailure) {
            if (count($failures) >= 32) {
                break;
            }
            $failures[] = $cleanupFailure;
        }
    }

    private function closeLogicalFrames(?LogicalScopeState $scope, ?LogicalScopeState $stopBefore = null): void
    {
        while ($scope instanceof LogicalScopeState && $scope !== $stopBefore) {
            if ($scope->attachments > 0) {
                $scope->draining = true;

                throw new ContainerException('Cannot reset a scope while child execution carriers are still attached.');
            }

            $parent = $scope->parent;
            $scope->close();
            $scope = $parent;
        }
    }

    private function invalidateResolvedEntry(string $id): void
    {
        foreach ($this->states as $state) {
            foreach (array_keys($state->resolvedScoped) as $scope) {
                unset($state->resolvedScoped[$scope][$id]);
                if ($state->resolvedScoped[$scope] === []) {
                    unset($state->resolvedScoped[$scope]);
                }
            }
        }
        $this->walkUniqueFrames(static function (LogicalScopeState $scope) use ($id): void {
            unset($scope->resolvedScoped[$id], $scope->constructing[$id]);
        });
    }

    private function logicalScopeForName(string $context, string $scope): ?LogicalScopeState
    {
        for ($current = $this->states[$context]->logicalCurrent ?? null; $current instanceof LogicalScopeState; $current = $current->parent) {
            if ($current->name === $scope) {
                return $current;
            }
        }

        return null;
    }

    private function promoteFastState(ExecutionScopeState $state): void
    {
        if ($state->currentScope === 'root') {
            throw new ContainerException('Cannot capture a scope context without an active scope.');
        }

        $parent = null;
        foreach ([...$state->scopeStack, $state->currentScope] as $scope) {
            if ($scope === 'root') {
                continue;
            }
            $parent = new LogicalScopeState(
                $scope,
                $parent,
                $state->scopeSeeds[$scope] ?? [],
                $state->resolvedScoped[$scope] ?? [],
            );
        }

        $state->logicalCurrent = $parent;
        $state->currentScope = 'root';
        $state->scopeStack = [];
        $state->scopeSeeds = [];
        $state->resolvedScoped = [];
    }

    private function resetLogicalState(string $context, ExecutionScopeState $state): void
    {
        $attached = $state->attachedScope;
        $this->closeLogicalFrames($state->logicalCurrent, $attached);

        if ($attached instanceof LogicalScopeState) {
            $attached->attachments = max(0, $attached->attachments - 1);
        }
        unset($this->states[$context]);
    }

    /** @param callable(LogicalScopeState): void $callback */
    private function walkUniqueFrames(callable $callback): void
    {
        $seen = [];
        foreach ($this->states as $state) {
            for ($scope = $state->logicalCurrent; $scope instanceof LogicalScopeState; $scope = $scope->parent) {
                $id = spl_object_id($scope);
                if (isset($seen[$id])) {
                    continue;
                }
                $seen[$id] = true;
                $callback($scope);
            }
        }
    }
}
