<?php

declare(strict_types=1);

namespace Infocyph\InterMix\DI\Internal;

use Infocyph\InterMix\DI\ScopeContext;
use Infocyph\InterMix\Exceptions\ContainerException;

/** @internal */
final class ExecutionScopeStore
{
    /** @var array<string, ExecutionScopeState> */
    private array $states = [];

    public function attachScopeContext(string $context, ScopeContext $scopeContext, object $owner): void
    {
        if (!$scopeContext instanceof CapturedScopeContext || !$scopeContext->belongsTo($owner)) {
            throw new ContainerException('Scope context belongs to a different container.');
        }

        $scope = $scopeContext->scope();
        if ($scope->closed) {
            throw new ContainerException('Scope context is no longer active.');
        }

        $state = $this->states[$context] ??= new ExecutionScopeState();
        if ($state->current instanceof LogicalScopeState || $state->attachedScope instanceof LogicalScopeState) {
            throw new ContainerException('Cannot attach a scope context while this execution carrier already has an active scope.');
        }

        ++$scope->attachments;
        $state->current = $scope;
        $state->attachedScope = $scope;
    }

    public function captureScopeContext(string $context, object $owner): ScopeContext
    {
        $scope = $this->states[$context]->current ?? null;
        if (!$scope instanceof LogicalScopeState || $scope->closed) {
            throw new ContainerException('Cannot capture a scope context without an active scope.');
        }

        return new CapturedScopeContext($owner, $scope);
    }

    public function detachScopeContext(string $context, ScopeContext $scopeContext, object $owner): void
    {
        if (!$scopeContext instanceof CapturedScopeContext || !$scopeContext->belongsTo($owner)) {
            throw new ContainerException('Scope context belongs to a different container.');
        }

        $state = $this->states[$context] ?? null;
        $scope = $scopeContext->scope();
        if (!$state instanceof ExecutionScopeState || $state->attachedScope !== $scope) {
            throw new ContainerException('Scope context is not attached to the current execution carrier.');
        }
        if ($state->current !== $scope) {
            throw new ContainerException('Cannot detach a scope context while a nested scope is still active.');
        }

        $state->current = null;
        $state->attachedScope = null;
        $scope->attachments = max(0, $scope->attachments - 1);
        unset($this->states[$context]);
    }

    /** @param array<string, mixed> $instances */
    public function enterScope(string $context, string $scope, array $instances = []): void
    {
        $state = $this->states[$context] ??= new ExecutionScopeState();
        $current = $state->current;
        if ($scope === 'root' || ($current instanceof LogicalScopeState && $current->contains($scope))) {
            throw new ContainerException("Scope \"{$scope}\" is already active.");
        }

        $state->current = new LogicalScopeState($scope, $current, $instances);
    }

    public function findScopeSeed(string $context, string $id, mixed &$value): bool
    {
        $scope = $this->states[$context]->current ?? null;
        if (!$scope instanceof LogicalScopeState || !array_key_exists($id, $scope->seeds)) {
            return false;
        }

        $value = $scope->seeds[$id];

        return true;
    }

    public function getResolvedScopedEntry(string $context, string $scope, string $id): mixed
    {
        $frame = $this->scopeForName($context, $scope);
        if (!$frame instanceof LogicalScopeState) {
            return null;
        }

        return $frame->resolvedScoped[$id] ?? null;
    }

    public function getScope(string $context): string
    {
        $current = $this->states[$context]->current ?? null;

        return $current instanceof LogicalScopeState ? $current->name : 'root';
    }

    public function hasNestedScopeOnAttachment(string $context, ScopeContext $scopeContext, object $owner): bool
    {
        if (!$scopeContext instanceof CapturedScopeContext || !$scopeContext->belongsTo($owner)) {
            throw new ContainerException('Scope context belongs to a different container.');
        }

        $state = $this->states[$context] ?? null;
        if (!$state instanceof ExecutionScopeState || $state->attachedScope !== $scopeContext->scope()) {
            throw new ContainerException('Scope context is not attached to the current execution carrier.');
        }

        return $state->current !== $state->attachedScope;
    }

    public function hasResolvedScoped(string $context, string $scope, string $id): bool
    {
        $frame = $this->scopeForName($context, $scope);

        return $frame instanceof LogicalScopeState && array_key_exists($id, $frame->resolvedScoped);
    }

    public function hasScopeSeeds(string $context): bool
    {
        $current = $this->states[$context]->current ?? null;

        return $current instanceof LogicalScopeState && $current->seeds !== [];
    }

    public function hasState(string $context): bool
    {
        return isset($this->states[$context]);
    }

    public function invalidateClass(string $class): void
    {
        $this->walkUniqueFrames(static function (LogicalScopeState $scope) use ($class): void {
            unset($scope->resolvedScoped[$class]);
        });
    }

    public function invalidateDefinition(string $id): void
    {
        $this->walkUniqueFrames(static function (LogicalScopeState $scope) use ($id): void {
            unset($scope->resolvedScoped[$id]);
        });
    }

    public function invalidateResolutionConfiguration(): void
    {
        $this->walkUniqueFrames(static function (LogicalScopeState $scope): void {
            $scope->resolvedScoped = [];
        });
    }

    public function isEmpty(): bool
    {
        return $this->states === [];
    }

    public function leaveScope(string $context): void
    {
        $state = $this->states[$context] ?? null;
        if (!$state instanceof ExecutionScopeState || !$state->current instanceof LogicalScopeState) {
            return;
        }

        $scope = $state->current;
        if ($state->attachedScope === $scope) {
            throw new ContainerException('Cannot leave an attached scope context; detach it instead.');
        }
        if ($scope->attachments > 0) {
            throw new ContainerException('Cannot leave a scope while child execution carriers are still attached.');
        }

        $scope->closed = true;
        $state->current = $scope->parent;
        if (!$state->current instanceof LogicalScopeState) {
            unset($this->states[$context]);
        }
    }

    /**
     * Promote the existing sequential fast-path state into logical frames only
     * when explicit cross-carrier propagation is first requested.
     *
     * @param array<int, string> $scopeStack
     * @param array<string, array<string, mixed>> $scopeSeeds
     * @param array<string, array<string, mixed>> $resolvedScoped
     */
    public function promoteScopeState(
        string $context,
        string $currentScope,
        array $scopeStack,
        array $scopeSeeds,
        array $resolvedScoped,
    ): void {
        if ($this->hasState($context)) {
            throw new ContainerException('Execution carrier scope state is already active.');
        }

        $parent = null;
        foreach ([...$scopeStack, $currentScope] as $scope) {
            if ($scope === 'root') {
                continue;
            }

            $parent = new LogicalScopeState(
                $scope,
                $parent,
                $scopeSeeds[$scope] ?? [],
                $resolvedScoped[$scope] ?? [],
            );
        }

        if (!$parent instanceof LogicalScopeState) {
            throw new ContainerException('Cannot capture a scope context without an active scope.');
        }

        $state = new ExecutionScopeState();
        $state->current = $parent;
        $this->states[$context] = $state;
    }

    public function resetAll(): void
    {
        foreach (array_keys($this->states) as $context) {
            $this->resetScope($context);
        }
    }

    public function resetScope(string $context): void
    {
        $state = $this->states[$context] ?? null;
        if (!$state instanceof ExecutionScopeState) {
            return;
        }

        if ($state->attachedScope instanceof LogicalScopeState) {
            for ($scope = $state->current; $scope instanceof LogicalScopeState && $scope !== $state->attachedScope; $scope = $scope->parent) {
                if ($scope->attachments > 0) {
                    throw new ContainerException('Cannot reset a scope while child execution carriers are still attached.');
                }
                $scope->closed = true;
            }

            $state->attachedScope->attachments = max(0, $state->attachedScope->attachments - 1);
            unset($this->states[$context]);

            return;
        }

        for ($scope = $state->current; $scope instanceof LogicalScopeState; $scope = $scope->parent) {
            if ($scope->attachments > 0) {
                throw new ContainerException('Cannot reset a scope while child execution carriers are still attached.');
            }
            $scope->closed = true;
        }

        unset($this->states[$context]);
    }

    public function setResolvedScoped(string $context, string $scope, string $id, mixed $value): void
    {
        $frame = $this->scopeForName($context, $scope);
        if (!$frame instanceof LogicalScopeState) {
            $state = $this->states[$context] ??= new ExecutionScopeState();
            $frame = new LogicalScopeState($scope, $state->current);
            $state->current = $frame;
        }

        $frame->resolvedScoped[$id] = $value;
    }

    public function setScope(string $context, string $scope): void
    {
        $state = $this->states[$context] ??= new ExecutionScopeState();
        $state->current = $scope === 'root' ? null : new LogicalScopeState($scope);
        $state->attachedScope = null;
        if ($scope === 'root') {
            unset($this->states[$context]);
        }
    }

    private function scopeForName(string $context, string $scope): ?LogicalScopeState
    {
        for ($current = $this->states[$context]->current ?? null; $current instanceof LogicalScopeState; $current = $current->parent) {
            if ($current->name === $scope) {
                return $current;
            }
        }

        return null;
    }

    /** @param callable(LogicalScopeState): void $callback */
    private function walkUniqueFrames(callable $callback): void
    {
        $seen = [];
        foreach ($this->states as $state) {
            for ($scope = $state->current; $scope instanceof LogicalScopeState; $scope = $scope->parent) {
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
