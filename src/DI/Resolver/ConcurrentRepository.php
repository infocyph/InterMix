<?php

declare(strict_types=1);

namespace Infocyph\InterMix\DI\Resolver;

use Infocyph\InterMix\DI\Internal\ExecutionContext;
use Infocyph\InterMix\DI\Internal\ExecutionScopeStore;
use Infocyph\InterMix\DI\ScopeContext;
use Infocyph\InterMix\Exceptions\ContainerException;
use Infocyph\InterMix\Exceptions\ScopeCleanupException;
use stdClass;
use Throwable;

/** @internal */
final class ConcurrentRepository extends Repository
{
    use ConcurrentScopeConstruction;
    use ConcurrentScopeLookup;

    private const string ROOT_CONTEXT = "\0intermix.root";

    private string $currentScope = 'root';

    private ?ExecutionScopeStore $executionScopes = null;

    /** @var array<string, array<string, mixed>> */
    private array $resolvedScoped = [];

    private bool $rootContextActive = false;

    private ?object $scopeContextOwner = null;

    /** @var array<string, array<string, mixed>> */
    private array $scopeSeeds = [];

    /** @var array<int, string> */
    private array $scopeStack = [];

    /** @var array<string, string> */
    private array $singletonConstructing = [];

    /**
     * Reject iteration when the owner-bound scope is no longer current on this carrier.
     */
    public function assertCurrentScopeContext(ScopeContext $scopeContext): void
    {
        $context = $this->activeExecutionContext();
        $store = $this->executionScopes;
        if ($context === null || !$store instanceof ExecutionScopeStore) {
            throw new ContainerException(
                'Tagged iterator scope is no longer active on the current execution carrier.',
            );
        }

        $store->assertCurrentScopeContext($context, $scopeContext, $this->scopeContextOwner());
    }

    /**
     * Borrow an active container-owned scope without replacing this carrier's existing scope.
     */
    public function attachScopeContext(ScopeContext $scopeContext): void
    {
        $physicalContext = ExecutionContext::id();
        if ($physicalContext === null) {
            if ($this->rootContextActive || $this->currentScope !== 'root') {
                throw new ContainerException(
                    'Cannot attach a scope context while this execution carrier already has an active scope.',
                );
            }
            $physicalContext = self::ROOT_CONTEXT;
        }

        ($this->executionScopes ??= new ExecutionScopeStore())->attachScopeContext(
            $physicalContext,
            $scopeContext,
            $this->scopeContextOwner(),
        );

        if ($physicalContext === self::ROOT_CONTEXT) {
            $this->rootContextActive = true;
        }
    }

    /**
     * Capture the active scope as a process-local handle owned by this repository.
     */
    public function captureScopeContext(): ScopeContext
    {
        $physicalContext = ExecutionContext::id();
        if ($physicalContext === null) {
            if (!$this->rootContextActive) {
                $this->promoteSequentialScope();
            }
            $physicalContext = self::ROOT_CONTEXT;
        }

        $store = $this->executionScopes;
        if (!$store instanceof ExecutionScopeStore || !$store->hasState($physicalContext)) {
            throw new ContainerException('Cannot capture a scope context without an active scope.');
        }

        return $store->captureScopeContext($physicalContext, $this->scopeContextOwner());
    }

    /**
     * Release the borrowed scope after checking ownership and nested-scope state.
     */
    public function detachScopeContext(ScopeContext $scopeContext): void
    {
        $physicalContext = ExecutionContext::id() ?? self::ROOT_CONTEXT;
        $store = $this->executionScopes;
        if (!$store instanceof ExecutionScopeStore) {
            throw new ContainerException('Scope context is not attached to the current execution carrier.');
        }

        while ($store->hasNestedScopeOnAttachment(
            $physicalContext,
            $scopeContext,
            $this->scopeContextOwner(),
        )) {
            $this->leaveExecutionScope($store, $physicalContext);
        }

        $store->detachScopeContext($physicalContext, $scopeContext, $this->scopeContextOwner());
        $this->finishExecutionContext($store, $physicalContext);
    }

    /**
     * Release an attachment if it is still active on this carrier.
     */
    public function detachScopeContextIfAttached(ScopeContext $scopeContext): void
    {
        $physicalContext = ExecutionContext::id() ?? self::ROOT_CONTEXT;
        $store = $this->executionScopes;
        if (!$store instanceof ExecutionScopeStore || !$store->isAttached($physicalContext)) {
            return;
        }

        $this->detachScopeContext($scopeContext);
    }

    /**
     * Push carrier-local state with seeds while preserving sequential root execution.
     *
     * @param array<string, mixed> $instances
     */
    public function enterScope(string $scope, array $instances = []): void
    {
        $context = $this->activeExecutionContext();
        if ($context !== null) {
            ($this->executionScopes ??= new ExecutionScopeStore())->enterScope($context, $scope, $instances);

            return;
        }

        if ($scope === $this->currentScope || in_array($scope, $this->scopeStack, true)) {
            throw new ContainerException("Scope \"{$scope}\" is already active.");
        }

        $this->scopeStack[] = $this->currentScope;
        $this->currentScope = $scope;
        if ($instances !== []) {
            $this->scopeSeeds[$scope] = $instances;
        }
    }

    /**
     * Invalidate class caches across sequential and carrier-local execution state.
     */
    public function invalidateClass(string $class): void
    {
        parent::invalidateClass($class);
        foreach (array_keys($this->resolvedScoped) as $scope) {
            unset($this->resolvedScoped[$scope][$class]);
            if ($this->resolvedScoped[$scope] === []) {
                unset($this->resolvedScoped[$scope]);
            }
        }
        $this->executionScopes?->invalidateClass($class);
    }

    /**
     * Invalidate definition caches across sequential and carrier-local execution state.
     */
    public function invalidateDefinition(string $id): void
    {
        parent::invalidateDefinition($id);
        foreach (array_keys($this->resolvedScoped) as $scope) {
            unset($this->resolvedScoped[$scope][$id]);
            if ($this->resolvedScoped[$scope] === []) {
                unset($this->resolvedScoped[$scope]);
            }
        }
        $this->executionScopes?->invalidateDefinition($id);
    }

    /**
     * Invalidate resolution metadata and all carrier-local cached results.
     */
    public function invalidateResolutionConfiguration(): void
    {
        parent::invalidateResolutionConfiguration();
        $this->resolvedScoped = [];
        $this->executionScopes?->invalidateResolutionConfiguration();
    }

    /**
     * Run bounded cleanup, release carrier-local values and restore the parent frame.
     */
    public function leaveScope(): void
    {
        $store = $this->executionScopes;
        if ($store instanceof ExecutionScopeStore) {
            $context = $this->activeExecutionContext();
            if ($context !== null) {
                $this->leaveExecutionScope($store, $context);

                return;
            }
        }

        $scope = $this->currentScope;
        ['failures' => $failures, 'count' => $failureCount] = $this->scopeLeaveFailures($scope);

        unset($this->resolvedScoped[$scope], $this->scopeSeeds[$scope]);
        $previous = array_pop($this->scopeStack);
        $this->currentScope = is_string($previous) ? $previous : 'root';

        if ($failures !== []) {
            throw new ScopeCleanupException($failures, $failureCount);
        }
    }

    /**
     * Framework-safe cleanup for only the currently executing carrier.
     *
     * Owned scopes are closed in LIFO order with normal leave hooks. An attached
     * carrier closes only its nested child frames and then releases its lease;
     * it never closes the shared owning scope.
     */
    public function resetCurrentExecutionScope(): void
    {
        $store = $this->executionScopes;
        $context = $this->activeExecutionContext();
        if ($store instanceof ExecutionScopeStore && $context !== null && $store->hasState($context)) {
            $result = $store->resetContext(
                $context,
                fn() => $this->leaveExecutionScope($store, $context),
            );
            $this->finishExecutionContext($store, $context);
            $this->throwCleanupFailures($result['failures'], $result['count']);

            return;
        }

        $this->resetSequentialExecutionScope();
    }

    /**
     * Drain or detach this carrier's execution scopes while retaining cleanup failures.
     */
    public function resetScope(): void
    {
        $store = $this->executionScopes;
        if ($store instanceof ExecutionScopeStore) {
            $context = $this->activeExecutionContext();
            if ($context !== null) {
                $store->resetScope($context);
                if ($context === self::ROOT_CONTEXT) {
                    $this->rootContextActive = false;
                }
                if ($store->isEmpty()) {
                    $this->executionScopes = null;
                }

                return;
            }
        }

        $this->resolvedScoped = [];
        $this->scopeStack = [];
        $this->scopeSeeds = [];
        $this->currentScope = 'root';
    }

    /**
     * Reject unsafe concurrent mutation before changing the environment and resetting scope state.
     */
    public function setEnvironment(string $env): void
    {
        if (parent::getEnvironment() === $env) {
            return;
        }

        $this->checkIfLocked();
        $this->executionScopes?->resetAll();
        parent::setEnvironment($env);
        $this->resolvedScoped = [];
        $this->scopeStack = [];
        $this->scopeSeeds = [];
        $this->currentScope = 'root';
        $this->rootContextActive = false;
        $this->executionScopes = null;
    }

    /**
     * Cache the scoped result in the current carrier's execution state.
     */
    public function setResolvedScoped(string $scope, string $id, mixed $value): void
    {
        $store = $this->executionScopes;
        if ($store instanceof ExecutionScopeStore) {
            $context = $this->activeExecutionContext();
            if ($context !== null) {
                $store->setResolvedScoped($context, $scope, $id, $value);

                return;
            }
        }

        $this->resolvedScoped[$scope][$id] = $value;
    }

    /**
     * Change this carrier's fast-path scope name without replacing a propagated logical scope.
     */
    public function setScope(string $scope): void
    {
        $store = $this->executionScopes;
        if ($store instanceof ExecutionScopeStore) {
            $context = $this->activeExecutionContext();
            if ($context !== null) {
                $store->setScope($context, $scope);
                if ($context === self::ROOT_CONTEXT && $scope === 'root') {
                    $this->rootContextActive = false;
                }

                return;
            }
        }

        $this->currentScope = $scope;
    }

    /**
     * Enforce both configuration locking and active-carrier mutation safety.
     */
    protected function checkIfLocked(): void
    {
        parent::checkIfLocked();
        $this->executionScopes?->assertMutationSafe($this->activeExecutionContext());
    }

    private function activeExecutionContext(): ?string
    {
        $context = ExecutionContext::id();
        if ($context !== null) {
            return $context;
        }

        return $this->rootContextActive ? self::ROOT_CONTEXT : null;
    }

    private function finishExecutionContext(ExecutionScopeStore $store, string $context): void
    {
        if ($context === self::ROOT_CONTEXT && !$store->hasState(self::ROOT_CONTEXT)) {
            $this->rootContextActive = false;
        }
        if ($store->isEmpty()) {
            $this->executionScopes = null;
        }
    }

    private function leaveExecutionScope(ExecutionScopeStore $store, string $context): void
    {
        $scope = $store->scopeForLeave($context);
        if (!isset($this->onScopeLeaveHooks[$scope])) {
            $store->leaveScope($context);
            $this->finishExecutionContext($store, $context);

            return;
        }

        ['failures' => $failures, 'count' => $failureCount] = $this->scopeLeaveFailures($scope);
        $store->leaveScope($context);
        $this->finishExecutionContext($store, $context);

        if ($failures !== []) {
            throw new ScopeCleanupException($failures, $failureCount);
        }
    }

    private function promoteSequentialScope(): void
    {
        if ($this->currentScope === 'root') {
            throw new ContainerException('Cannot capture a scope context without an active scope.');
        }

        ($this->executionScopes ??= new ExecutionScopeStore())->promoteScopeState(
            self::ROOT_CONTEXT,
            $this->currentScope,
            $this->scopeStack,
            $this->scopeSeeds,
            $this->resolvedScoped,
        );

        $this->currentScope = 'root';
        $this->scopeStack = [];
        $this->scopeSeeds = [];
        $this->resolvedScoped = [];
        $this->rootContextActive = true;
    }

    private function resetSequentialExecutionScope(): void
    {
        $failures = [];
        $failureCount = 0;

        while ($this->currentScope !== 'root') {
            try {
                $this->leaveScope();
            } catch (ScopeCleanupException $failure) {
                $failureCount += $failure->cleanupFailureCount;
                foreach ($failure->cleanupFailures as $cleanupFailure) {
                    if (count($failures) >= 32) {
                        break;
                    }
                    $failures[] = $cleanupFailure;
                }
            }
        }

        $this->throwCleanupFailures($failures, $failureCount);
    }

    private function scopeContextOwner(): object
    {
        return $this->scopeContextOwner ??= new stdClass();
    }

    /** @return array{failures: list<Throwable>, count: int} */
    private function scopeLeaveFailures(string $scope): array
    {
        $failures = [];
        $failureCount = 0;
        foreach ($this->onScopeLeaveHooks[$scope] ?? [] as $hook) {
            try {
                $hook($scope, $this->container());
            } catch (Throwable $throwable) {
                ++$failureCount;
                if (count($failures) < 32) {
                    $failures[] = $throwable;
                }
            }
        }

        return ['failures' => $failures, 'count' => $failureCount];
    }

    /** @param list<Throwable> $failures */
    private function throwCleanupFailures(array $failures, int $failureCount): void
    {
        if ($failureCount > 0) {
            throw new ScopeCleanupException($failures, $failureCount);
        }
    }
}
