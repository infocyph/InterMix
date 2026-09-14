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

    public function assertCanLeaveScope(string $context): void
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
    }

    public function assertMutationSafe(?string $currentContext): void
    {
        if ($this->hasConcurrentActivity($currentContext)) {
            throw new ContainerException(
                'Cannot mutate container configuration while concurrent scope execution is active.',
            );
        }
    }

    public function attachScopeContext(string $context, ScopeContext $scopeContext, object $owner): void
    {
        $scope = $this->unwrapScopeContext($scopeContext, $owner);
        if ($scope->closed) {
            throw new ContainerException('Scope context is no longer active.');
        }

        $state = $this->states[$context] ??= new ExecutionScopeState();
        if ($this->stateHasActiveScope($state)) {
            throw new ContainerException('Cannot attach a scope context while this execution carrier already has an active scope.');
        }

        ++$scope->attachments;
        $state->current = $scope;
        $state->attachedScope = $scope;
    }

    public function beginScopedConstruction(string $context, string $scope, string $id): bool
    {
        $frame = $this->logicalScopeForName($context, $scope);
        if (!$frame instanceof LogicalScopeState) {
            return false;
        }

        $constructingCarrier = $frame->constructing[$id] ?? null;
        if ($constructingCarrier !== null) {
            if ($constructingCarrier === $context) {
                return false;
            }

            throw new ContainerException(
                "Scoped service '{$id}' is already being constructed by another execution carrier in scope '{$scope}'.",
            );
        }

        $frame->constructing[$id] = $context;

        return true;
    }

    public function captureScopeContext(string $context, object $owner): ScopeContext
    {
        $state = $this->states[$context] ?? null;
        if (!$state instanceof ExecutionScopeState) {
            throw new ContainerException('Cannot capture a scope context without an active scope.');
        }
        if (!$state->current instanceof LogicalScopeState) {
            $this->promoteFastState($state);
        }

        $scope = $state->current;
        if (!$scope instanceof LogicalScopeState || $scope->closed) {
            throw new ContainerException('Cannot capture a scope context without an active scope.');
        }

        return new CapturedScopeContext($owner, $scope);
    }

    public function detachCurrentScopeContext(string $context): void
    {
        $state = $this->states[$context] ?? null;
        if (!$state instanceof ExecutionScopeState || !$state->attachedScope instanceof LogicalScopeState) {
            return;
        }
        if ($state->current !== $state->attachedScope) {
            throw new ContainerException('Cannot detach a scope context while a nested scope is still active.');
        }

        $state->attachedScope->attachments = max(0, $state->attachedScope->attachments - 1);
        unset($this->states[$context]);
    }

    public function detachScopeContext(string $context, ScopeContext $scopeContext, object $owner): void
    {
        $scope = $this->unwrapScopeContext($scopeContext, $owner);
        $state = $this->states[$context] ?? null;
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

    public function endScopedConstruction(string $context, string $scope, string $id): void
    {
        $frame = $this->logicalScopeForName($context, $scope);
        if ($frame instanceof LogicalScopeState && ($frame->constructing[$id] ?? null) === $context) {
            unset($frame->constructing[$id]);
        }
    }

    /** @param array<string, mixed> $instances */
    public function enterScope(string $context, string $scope, array $instances = []): void
    {
        $state = $this->states[$context] ??= new ExecutionScopeState();
        if ($state->current instanceof LogicalScopeState || $state->attachedScope instanceof LogicalScopeState) {
            $this->enterLogicalScope($state, $scope, $instances);

            return;
        }

        if ($scope === $state->fastCurrentScope || in_array($scope, $state->fastScopeStack, true)) {
            throw new ContainerException("Scope \"{$scope}\" is already active.");
        }

        $state->fastScopeStack[] = $state->fastCurrentScope;
        $state->fastCurrentScope = $scope;
        if ($instances !== []) {
            $state->fastScopeSeeds[$scope] = $instances;
        }
    }

    public function findScopeSeed(string $context, string $id, mixed &$value): bool
    {
        $state = $this->states[$context] ?? null;
        if (!$state instanceof ExecutionScopeState) {
            return false;
        }
        if ($state->current instanceof LogicalScopeState) {
            if (!array_key_exists($id, $state->current->seeds)) {
                return false;
            }
            $value = $state->current->seeds[$id];

            return true;
        }

        $seeds = $state->fastScopeSeeds[$state->fastCurrentScope] ?? null;
        if (!is_array($seeds) || !array_key_exists($id, $seeds)) {
            return false;
        }
        $value = $seeds[$id];

        return true;
    }

    public function getResolvedScopedEntry(string $context, string $scope, string $id): mixed
    {
        $state = $this->states[$context] ?? null;
        if (!$state instanceof ExecutionScopeState) {
            return null;
        }
        if (!$state->current instanceof LogicalScopeState) {
            return $state->fastResolvedScoped[$scope][$id] ?? null;
        }

        return $this->logicalScopeForName($context, $scope)?->resolvedScoped[$id] ?? null;
    }

    public function getScope(string $context): string
    {
        $state = $this->states[$context] ?? null;
        if (!$state instanceof ExecutionScopeState) {
            return 'root';
        }

        return $state->current instanceof LogicalScopeState
            ? $state->current->name
            : $state->fastCurrentScope;
    }

    public function hasConcurrentActivity(?string $currentContext): bool
    {
        foreach ($this->states as $context => $state) {
            if ($context !== $currentContext || $state->attachedScope instanceof LogicalScopeState) {
                return true;
            }

            for ($scope = $state->current; $scope instanceof LogicalScopeState; $scope = $scope->parent) {
                if ($scope->attachments > 0) {
                    return true;
                }
            }
        }

        return false;
    }

    public function hasNestedScope(string $context): bool
    {
        $state = $this->states[$context] ?? null;

        return $state instanceof ExecutionScopeState
            && $state->attachedScope instanceof LogicalScopeState
            && $state->current instanceof LogicalScopeState
            && $state->current !== $state->attachedScope;
    }

    public function hasNestedScopeOnAttachment(string $context, ScopeContext $scopeContext, object $owner): bool
    {
        $scope = $this->unwrapScopeContext($scopeContext, $owner);
        $state = $this->states[$context] ?? null;
        if (!$state instanceof ExecutionScopeState || $state->attachedScope !== $scope) {
            throw new ContainerException('Scope context is not attached to the current execution carrier.');
        }

        return $state->current !== $state->attachedScope;
    }

    public function hasResolvedScoped(string $context, string $scope, string $id): bool
    {
        $state = $this->states[$context] ?? null;
        if (!$state instanceof ExecutionScopeState) {
            return false;
        }
        if (!$state->current instanceof LogicalScopeState) {
            return array_key_exists($id, $state->fastResolvedScoped[$scope] ?? []);
        }

        $frame = $this->logicalScopeForName($context, $scope);

        return $frame instanceof LogicalScopeState && array_key_exists($id, $frame->resolvedScoped);
    }

    public function hasScopeSeeds(string $context): bool
    {
        $state = $this->states[$context] ?? null;
        if (!$state instanceof ExecutionScopeState) {
            return false;
        }

        return $state->current instanceof LogicalScopeState
            ? $state->current->seeds !== []
            : $state->fastScopeSeeds !== [];
    }

    public function hasState(string $context): bool
    {
        return isset($this->states[$context]);
    }

    public function invalidateClass(string $class): void
    {
        foreach ($this->states as $state) {
            foreach (array_keys($state->fastResolvedScoped) as $scope) {
                unset($state->fastResolvedScoped[$scope][$class]);
                if ($state->fastResolvedScoped[$scope] === []) {
                    unset($state->fastResolvedScoped[$scope]);
                }
            }
        }
        $this->walkUniqueFrames(static function (LogicalScopeState $scope) use ($class): void {
            unset($scope->resolvedScoped[$class], $scope->constructing[$class]);
        });
    }

    public function invalidateDefinition(string $id): void
    {
        foreach ($this->states as $state) {
            foreach (array_keys($state->fastResolvedScoped) as $scope) {
                unset($state->fastResolvedScoped[$scope][$id]);
                if ($state->fastResolvedScoped[$scope] === []) {
                    unset($state->fastResolvedScoped[$scope]);
                }
            }
        }
        $this->walkUniqueFrames(static function (LogicalScopeState $scope) use ($id): void {
            unset($scope->resolvedScoped[$id], $scope->constructing[$id]);
        });
    }

    public function invalidateResolutionConfiguration(): void
    {
        foreach ($this->states as $state) {
            $state->fastResolvedScoped = [];
        }
        $this->walkUniqueFrames(static function (LogicalScopeState $scope): void {
            $scope->resolvedScoped = [];
            $scope->constructing = [];
        });
    }

    public function isAttached(string $context): bool
    {
        return ($this->states[$context]->attachedScope ?? null) instanceof LogicalScopeState;
    }

    public function isEmpty(): bool
    {
        return $this->states === [];
    }

    public function leaveScope(string $context): void
    {
        $state = $this->states[$context] ?? null;
        if (!$state instanceof ExecutionScopeState) {
            return;
        }
        if (!$state->current instanceof LogicalScopeState) {
            $this->leaveFastScope($context, $state);

            return;
        }

        $this->assertCanLeaveScope($context);
        $scope = $state->current;
        $scope->closed = true;
        $scope->constructing = [];
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

        $state = new ExecutionScopeState();
        $state->fastCurrentScope = $currentScope;
        $state->fastScopeStack = $scopeStack;
        $state->fastScopeSeeds = $scopeSeeds;
        $state->fastResolvedScoped = $resolvedScoped;
        $this->promoteFastState($state);
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
        if (!$state->current instanceof LogicalScopeState) {
            unset($this->states[$context]);

            return;
        }

        $this->resetLogicalState($context, $state);
    }

    public function setResolvedScoped(string $context, string $scope, string $id, mixed $value): void
    {
        $state = $this->states[$context] ??= new ExecutionScopeState();
        if (!$state->current instanceof LogicalScopeState) {
            $state->fastResolvedScoped[$scope][$id] = $value;

            return;
        }

        $frame = $this->logicalScopeForName($context, $scope);
        if (!$frame instanceof LogicalScopeState) {
            $frame = new LogicalScopeState($scope, $state->current);
            $state->current = $frame;
        }
        $frame->resolvedScoped[$id] = $value;
    }

    public function setScope(string $context, string $scope): void
    {
        $state = $this->states[$context] ??= new ExecutionScopeState();
        if (!$state->current instanceof LogicalScopeState && !$state->attachedScope instanceof LogicalScopeState) {
            $state->fastCurrentScope = $scope;
            if ($scope === 'root'
                && $state->fastScopeStack === []
                && $state->fastScopeSeeds === []
                && $state->fastResolvedScoped === []
            ) {
                unset($this->states[$context]);
            }

            return;
        }

        $state->current = $scope === 'root' ? null : new LogicalScopeState($scope);
        $state->attachedScope = null;
        if ($scope === 'root') {
            unset($this->states[$context]);
        }
    }

    /** @param array<string, mixed> $instances */
    private function enterLogicalScope(ExecutionScopeState $state, string $scope, array $instances): void
    {
        $current = $state->current;
        if ($scope === 'root' || ($current instanceof LogicalScopeState && $current->contains($scope))) {
            throw new ContainerException("Scope \"{$scope}\" is already active.");
        }

        $state->current = new LogicalScopeState($scope, $current, $instances);
    }

    private function leaveFastScope(string $context, ExecutionScopeState $state): void
    {
        $scope = $state->fastCurrentScope;
        unset($state->fastResolvedScoped[$scope], $state->fastScopeSeeds[$scope]);
        $previous = array_pop($state->fastScopeStack);
        $state->fastCurrentScope = is_string($previous) ? $previous : 'root';

        if ($state->fastCurrentScope === 'root'
            && $state->fastScopeStack === []
            && $state->fastScopeSeeds === []
            && $state->fastResolvedScoped === []
        ) {
            unset($this->states[$context]);
        }
    }

    private function logicalScopeForName(string $context, string $scope): ?LogicalScopeState
    {
        for ($current = $this->states[$context]->current ?? null; $current instanceof LogicalScopeState; $current = $current->parent) {
            if ($current->name === $scope) {
                return $current;
            }
        }

        return null;
    }

    private function promoteFastState(ExecutionScopeState $state): void
    {
        if ($state->fastCurrentScope === 'root') {
            throw new ContainerException('Cannot capture a scope context without an active scope.');
        }

        $parent = null;
        foreach ([...$state->fastScopeStack, $state->fastCurrentScope] as $scope) {
            if ($scope === 'root') {
                continue;
            }
            $parent = new LogicalScopeState(
                $scope,
                $parent,
                $state->fastScopeSeeds[$scope] ?? [],
                $state->fastResolvedScoped[$scope] ?? [],
            );
        }

        $state->current = $parent;
        $state->fastCurrentScope = 'root';
        $state->fastScopeStack = [];
        $state->fastScopeSeeds = [];
        $state->fastResolvedScoped = [];
    }

    private function resetLogicalState(string $context, ExecutionScopeState $state): void
    {
        if ($state->attachedScope instanceof LogicalScopeState) {
            for ($scope = $state->current; $scope instanceof LogicalScopeState && $scope !== $state->attachedScope; $scope = $scope->parent) {
                if ($scope->attachments > 0) {
                    throw new ContainerException('Cannot reset a scope while child execution carriers are still attached.');
                }
                $scope->closed = true;
                $scope->constructing = [];
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
            $scope->constructing = [];
        }
        unset($this->states[$context]);
    }

    private function stateHasActiveScope(ExecutionScopeState $state): bool
    {
        return $state->current instanceof LogicalScopeState
            || $state->attachedScope instanceof LogicalScopeState
            || $state->fastCurrentScope !== 'root';
    }

    private function unwrapScopeContext(ScopeContext $scopeContext, object $owner): LogicalScopeState
    {
        if (!$scopeContext instanceof CapturedScopeContext) {
            throw new ContainerException('Scope context belongs to a different container.');
        }

        return $scopeContext->unwrap($owner);
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
