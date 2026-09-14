<?php

declare(strict_types=1);

namespace Infocyph\InterMix\DI\Resolver;

use Infocyph\InterMix\DI\Internal\ExecutionContext;
use Infocyph\InterMix\DI\Internal\ExecutionScopeStore;
use Infocyph\InterMix\DI\ScopeContext;
use Infocyph\InterMix\Exceptions\ContainerException;
use stdClass;

/** @internal */
final class ConcurrentRepository extends Repository
{
    private const string ROOT_CONTEXT = "\0intermix.root";

    private string $currentScope = 'root';

    private ?ExecutionScopeStore $executionScopes = null;

    /** @var array<string, array<string, mixed>> */
    private array $resolvedScoped = [];

    private bool $rootContextActive = false;

    private ?object $scopeContextOwner = null;

    /** @var array<string, array<int, callable(string, \Infocyph\InterMix\DI\Container): void>> */
    private array $scopeLeaveHooks = [];

    /** @var array<string, array<string, mixed>> */
    private array $scopeSeeds = [];

    /** @var array<int, string> */
    private array $scopeStack = [];

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
        if ($physicalContext === self::ROOT_CONTEXT && !$store->hasState(self::ROOT_CONTEXT)) {
            $this->rootContextActive = false;
        }
        if ($store->isEmpty()) {
            $this->executionScopes = null;
        }
    }

    /** @param array<string, mixed> $instances */
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

    /** @internal */
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

    /** @internal */
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

    /** @internal */
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

    /** @internal */
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

    public function invalidateResolutionConfiguration(): void
    {
        parent::invalidateResolutionConfiguration();
        $this->resolvedScoped = [];
        $this->executionScopes?->invalidateResolutionConfiguration();
    }

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
        foreach ($this->scopeLeaveHooks[$scope] ?? [] as $hook) {
            $hook($scope, $this->container());
        }

        unset($this->resolvedScoped[$scope], $this->scopeSeeds[$scope]);
        $previous = array_pop($this->scopeStack);
        $this->currentScope = is_string($previous) ? $previous : 'root';
    }

    public function onScopeLeave(string $scope, callable $hook): void
    {
        parent::onScopeLeave($scope, $hook);
        $this->scopeLeaveHooks[$scope][] = $hook;
    }

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

    public function setEnvironment(string $env): void
    {
        if (parent::getEnvironment() === $env) {
            return;
        }

        $this->executionScopes?->resetAll();
        parent::setEnvironment($env);
        $this->resolvedScoped = [];
        $this->scopeStack = [];
        $this->scopeSeeds = [];
        $this->currentScope = 'root';
        $this->rootContextActive = false;
        $this->executionScopes = null;
    }

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

    private function activeExecutionContext(): ?string
    {
        $context = ExecutionContext::id();
        if ($context !== null) {
            return $context;
        }

        return $this->rootContextActive ? self::ROOT_CONTEXT : null;
    }

    private function leaveExecutionScope(ExecutionScopeStore $store, string $context): void
    {
        $scope = $store->getScope($context);
        foreach ($this->scopeLeaveHooks[$scope] ?? [] as $hook) {
            $hook($scope, $this->container());
        }
        $store->leaveScope($context);
        if ($context === self::ROOT_CONTEXT && !$store->hasState(self::ROOT_CONTEXT)) {
            $this->rootContextActive = false;
        }
        if ($store->isEmpty()) {
            $this->executionScopes = null;
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

    private function scopeContextOwner(): object
    {
        return $this->scopeContextOwner ??= new stdClass();
    }
}
