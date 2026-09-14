<?php

declare(strict_types=1);

namespace Infocyph\InterMix\DI\Internal;

use Infocyph\InterMix\DI\ScopeContext;
use Infocyph\InterMix\Exceptions\ContainerException;
use stdClass;

/**
 * Carrier-local activation and shared logical scope storage for compiled runtimes.
 *
 * @internal
 */
final class ProductionScopeStore
{
    private const string ROOT_CONTEXT = "\0intermix.production.root";

    private const string SEQUENTIAL_CONSTRUCTION_CONTEXT = "\0intermix.production.sequential";

    /** @var array<string, ProductionExecutionScopeState> */
    private array $states = [];

    private bool $rootContextActive = false;

    private ?object $owner = null;

    public function activeContext(): ?string
    {
        $context = ExecutionContext::id();
        if ($context !== null) {
            return $context;
        }

        return $this->rootContextActive ? self::ROOT_CONTEXT : null;
    }

    public function attach(ScopeState $scope, ScopeState $sequentialScope): string
    {
        if ($scope->closed) {
            throw new ContainerException('Scope context is no longer active.');
        }

        $context = ExecutionContext::id();
        if ($context === null) {
            if ($this->rootContextActive || $sequentialScope->name !== 'root') {
                throw new ContainerException(
                    'Cannot attach a scope context while this execution carrier already has an active scope.',
                );
            }
            $context = self::ROOT_CONTEXT;
            $this->rootContextActive = true;
        }

        $state = $this->states[$context] ??= new ProductionExecutionScopeState();
        if ($state->current instanceof ScopeState || $state->attachedScope instanceof ScopeState) {
            throw new ContainerException('Cannot attach a scope context while this execution carrier already has an active scope.');
        }

        ++$scope->attachments;
        $state->current = $scope;
        $state->attachedScope = $scope;

        return $context;
    }

    /**
     * @return array{ProductionCapturedScopeContext, ScopeState}
     */
    public function capture(ScopeState $sequentialScope, ?ScopeContext $fallbackContext): array
    {
        $context = ExecutionContext::id();
        if ($context === null && !$this->rootContextActive) {
            if ($sequentialScope->name === 'root') {
                throw new ContainerException('Cannot capture a scope context without an active scope.');
            }

            $state = new ProductionExecutionScopeState();
            $state->current = $sequentialScope;
            $this->states[self::ROOT_CONTEXT] = $state;
            $this->rootContextActive = true;
            $context = self::ROOT_CONTEXT;
            $sequentialScope = new ScopeState('root');
        } elseif ($context === null) {
            $context = self::ROOT_CONTEXT;
        }

        $scope = $this->states[$context]->current ?? null;
        if (!$scope instanceof ScopeState || $scope->name === 'root' || $scope->closed) {
            throw new ContainerException('Cannot capture a scope context without an active scope.');
        }

        return [
            new ProductionCapturedScopeContext($this->owner(), $scope, $fallbackContext),
            $sequentialScope,
        ];
    }

    public function constructScoped(ScopeState $scope, int $slot, string $id, callable $resolver): mixed
    {
        $carrier = $this->activeContext() ?? self::SEQUENTIAL_CONSTRUCTION_CONTEXT;
        $constructingCarrier = $scope->constructing[$slot] ?? null;
        if ($constructingCarrier !== null) {
            if ($constructingCarrier === $carrier) {
                return $resolver();
            }

            throw new ContainerException(
                "Scoped service '{$id}' is already being constructed by another execution carrier in scope '{$scope->name}'.",
            );
        }

        $scope->constructing[$slot] = $carrier;

        try {
            return $scope->resolved[$slot] = $resolver();
        } finally {
            if (($scope->constructing[$slot] ?? null) === $carrier) {
                unset($scope->constructing[$slot]);
            }
        }
    }

    public function current(ScopeState $sequentialScope): ScopeState
    {
        $context = $this->activeContext();
        if ($context === null) {
            return $sequentialScope;
        }

        $state = $this->states[$context] ??= new ProductionExecutionScopeState();

        return $state->current ??= new ScopeState('root');
    }

    /** @param callable(ScopeState): void $beforeClose */
    public function detach(string $context, ScopeState $scope, callable $beforeClose): void
    {
        $state = $this->states[$context] ?? null;
        if (!$state instanceof ProductionExecutionScopeState || $state->attachedScope !== $scope) {
            return;
        }

        while ($state->current instanceof ScopeState && $state->current !== $scope) {
            $this->closeContextScope($context, $beforeClose);
            $state = $this->states[$context] ?? null;
            if (!$state instanceof ProductionExecutionScopeState) {
                return;
            }
        }

        $scope->attachments = max(0, $scope->attachments - 1);
        unset($this->states[$context]);
        $this->finishContext($context);
    }

    /** @param array<int, mixed> $seeds @param array<string, mixed> $rawSeeds */
    public function enter(string $context, string $scope, array $seeds, array $rawSeeds): void
    {
        $state = $this->states[$context] ??= new ProductionExecutionScopeState();
        $current = $state->current ??= new ScopeState('root');
        if ($scope === 'root' || $current->contains($scope)) {
            throw new ContainerException("Scope \"{$scope}\" is already active.");
        }

        $state->current = new ScopeState($scope, $current, $seeds, $rawSeeds);
    }

    public function isEmpty(): bool
    {
        return $this->states === [];
    }

    /** @param callable(ScopeState): void $beforeClose */
    public function leaveCurrent(ScopeState $sequentialScope, callable $beforeClose): ScopeState
    {
        $context = $this->activeContext();
        if ($context === null) {
            return $this->closeSequentialScope($sequentialScope, $beforeClose);
        }

        $this->closeContextScope($context, $beforeClose);

        return $sequentialScope;
    }

    /** @param callable(ScopeState): void $beforeClose */
    public function resetCurrent(ScopeState $sequentialScope, callable $beforeClose): ScopeState
    {
        $context = $this->activeContext();
        if ($context === null) {
            while ($sequentialScope->name !== 'root') {
                $sequentialScope = $this->closeSequentialScope($sequentialScope, $beforeClose);
            }

            return $sequentialScope;
        }

        $state = $this->states[$context] ?? null;
        if (!$state instanceof ProductionExecutionScopeState || !$state->current instanceof ScopeState) {
            return $sequentialScope;
        }

        if ($state->attachedScope instanceof ScopeState) {
            while ($state->current !== $state->attachedScope) {
                $this->closeContextScope($context, $beforeClose);
                $state = $this->states[$context];
            }

            $state->attachedScope->attachments = max(0, $state->attachedScope->attachments - 1);
            unset($this->states[$context]);
            $this->finishContext($context);

            return $sequentialScope;
        }

        while (($this->states[$context]->current ?? null) instanceof ScopeState
            && $this->states[$context]->current->name !== 'root'
        ) {
            $this->closeContextScope($context, $beforeClose);
        }

        if (($this->states[$context]->current ?? null)?->name === 'root') {
            unset($this->states[$context]);
            $this->finishContext($context);
        }

        return $sequentialScope;
    }

    /** @return array{ScopeState, ScopeContext|null} */
    public function unwrap(ScopeContext $scopeContext): array
    {
        if (!$scopeContext instanceof ProductionCapturedScopeContext) {
            throw new ContainerException('Scope context belongs to a different container.');
        }

        return $scopeContext->unwrap($this->owner());
    }

    /** @param callable(ScopeState): void $beforeClose */
    private function closeContextScope(string $context, callable $beforeClose): void
    {
        $state = $this->states[$context] ?? null;
        $current = $state?->current;
        if (!$state instanceof ProductionExecutionScopeState || !$current instanceof ScopeState || $current->name === 'root') {
            return;
        }
        if ($state->attachedScope === $current) {
            throw new ContainerException('Cannot leave an attached scope context; detach it instead.');
        }

        $this->assertNoAttachments($current);
        $beforeClose($current);
        $this->close($current);

        $parent = $current->parent;
        if ($parent instanceof ScopeState && $parent->name !== 'root') {
            $state->current = $parent;

            return;
        }

        unset($this->states[$context]);
        $this->finishContext($context);
    }

    /** @param callable(ScopeState): void $beforeClose */
    private function closeSequentialScope(ScopeState $scope, callable $beforeClose): ScopeState
    {
        if ($scope->name === 'root') {
            return $scope;
        }

        $this->assertNoAttachments($scope);
        $beforeClose($scope);
        $this->close($scope);

        return $scope->parent ?? new ScopeState('root');
    }

    private function assertNoAttachments(ScopeState $scope): void
    {
        if ($scope->attachments > 0) {
            throw new ContainerException('Cannot leave a scope while child execution carriers are still attached.');
        }
    }

    private function close(ScopeState $scope): void
    {
        $scope->closed = true;
        $scope->constructing = [];
    }

    private function finishContext(string $context): void
    {
        if ($context === self::ROOT_CONTEXT && !isset($this->states[self::ROOT_CONTEXT])) {
            $this->rootContextActive = false;
        }
    }

    private function owner(): object
    {
        return $this->owner ??= new stdClass();
    }
}
