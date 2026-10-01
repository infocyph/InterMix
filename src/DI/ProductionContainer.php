<?php

declare(strict_types=1);

namespace Infocyph\InterMix\DI;

use Infocyph\InterMix\DI\Internal\ConfigurationContainer;
use Infocyph\InterMix\DI\Internal\ExecutionContext;
use Infocyph\InterMix\DI\Internal\ProductionFallbackState;
use Infocyph\InterMix\DI\Internal\ProductionScopeStore;
use Infocyph\InterMix\DI\Internal\RuntimeIslandResolver;
use Infocyph\InterMix\DI\Internal\ScopeState;
use Infocyph\InterMix\DI\Support\LifetimeEnum;
use Infocyph\InterMix\Exceptions\ContainerException;
use Infocyph\InterMix\Exceptions\ScopeCleanupException;
use Infocyph\InterMix\Internal\ReflectionResource;
use Psr\Container\ContainerInterface;
use Throwable;

abstract class ProductionContainer implements RuntimeContainerInterface
{
    protected bool $compiledSingletonResolutionActive = false;

    protected bool $contextScopesActive = false;

    protected ScopeState $scope;

    /** @var array<int|string, int> */
    private array $compiledSingletonResolutionOwners = [];

    /**
     * @var array<string, array{exists: bool, definition: mixed, lifetime: LifetimeEnum, tags: array<int, string>}>
     */
    private array $fallbackDefinitions = [];

    private ?ProductionScopeStore $productionScopes = null;

    private ?RuntimeIslandResolver $runtimeIslands = null;

    public function __construct(private ?ConfigurationContainer $fallback = null)
    {
        $this->scope = new ScopeState('root');
        if ($fallback instanceof ConfigurationContainer) {
            $this->captureFallbackDefinitions($fallback);
            $this->installFallbackBridges($fallback);
        }
    }

    abstract protected function slotFor(string $id): ?int;

    final public function captureScopeContext(): ScopeContext
    {
        $fallbackContext = $this->fallback?->captureScopeContext();
        [$captured, $scope] = $this->scopeStore()->capture($this->scope, $fallbackContext);
        $this->scope = $scope;
        $this->refreshScopeActivity();

        return $captured;
    }

    /** @param array<int|string, mixed> $arguments */
    final public function invoke(callable $callable, array $arguments = []): mixed
    {
        return $this->dynamic()->invoke($callable, $arguments);
    }

    /** @param array<int|string, mixed> $arguments */
    final public function make(string $class, array $arguments = []): object
    {
        return $this->dynamic()->make($class, $arguments);
    }

    /**
     * Reset only the current execution carrier's DI scope state.
     *
     * Attached carriers close only their nested frames and release the attachment;
     * owning carriers close their own frames in deterministic LIFO order.
     */
    final public function resetCurrentExecutionScope(): void
    {
        if (!$this->contextScopesActive || !$this->productionScopes instanceof ProductionScopeStore) {
            while ($this->scope->name !== 'root') {
                $this->leaveSequentialScope(true);
            }

            return;
        }

        $this->scope = $this->productionScopes->resetCurrent(
            $this->scope,
            fn(ScopeState $closing) => $this->beforeScopeClose($closing, true),
        );
        $this->fallback?->resetCurrentExecutionScope();
        $this->refreshScopeActivity();
    }

    /** @return iterable<string, mixed> */
    final public function tagged(string $tag): iterable
    {
        $scopeContext = $this->currentExecutionScope()->name === 'root'
            ? null
            : $this->captureScopeContext();

        return $this->taggedValues(
            $tag,
            $scopeContext,
            $this->taggedIds($tag),
            $this->fallback,
        );
    }

    /** @param array<string, mixed> $instances */
    final public function withinScope(string $scope, callable $callback, array $instances = []): mixed
    {
        $this->enterScope($scope, $instances);
        $failure = null;

        try {
            return $callback($this);
        } catch (Throwable $throwable) {
            $failure = $throwable;

            throw $throwable;
        } finally {
            try {
                $this->leaveScope();
            } catch (ScopeCleanupException $cleanupFailure) {
                if ($failure === null) {
                    throw $cleanupFailure;
                }

                throw new ScopeCleanupException(
                    $cleanupFailure->cleanupFailures,
                    $cleanupFailure->cleanupFailureCount,
                    $failure,
                );
            }
        }
    }

    final public function withinScopeContext(ScopeContext $scopeContext, callable $callback): mixed
    {
        [$scope, $fallbackContext] = $this->scopeStore()->unwrap($scopeContext);
        $context = $this->scopeStore()->attach($scope, $this->scope);
        $this->refreshScopeActivity();

        if ($this->fallback instanceof ConfigurationContainer && $fallbackContext instanceof ScopeContext) {
            try {
                return $this->fallback->withinScopeContext(
                    $fallbackContext,
                    function () use ($callback, $context, $scope): mixed {
                        try {
                            return $callback($this);
                        } finally {
                            $this->detachScopeContext($context, $scope, true);
                        }
                    },
                );
            } finally {
                $this->detachScopeContext($context, $scope, false);
            }
        }

        try {
            return $callback($this);
        } finally {
            $this->detachScopeContext($context, $scope, false);
        }
    }

    final protected function applyCompiledRuntimePropertyAttribute(
        object $instance,
        string $declaringClass,
        string $propertyName,
    ): void {
        $this->runtimeIslandResolver()->applyAttributedProperty($instance, $declaringClass, $propertyName);
    }

    final protected function assertCompiledScopedResolution(string $id): void
    {
        if (!$this->compiledSingletonResolutionActive) {
            return;
        }

        $owner = ExecutionContext::id() ?? "\0intermix.production.root";
        if (($this->compiledSingletonResolutionOwners[$owner] ?? 0) > 0) {
            throw new ContainerException(
                "Singleton construction cannot capture scoped entry '$id'.",
            );
        }
    }

    /** @param class-string $declaringClass */
    final protected function assignCompiledRuntimeProperty(
        object $instance,
        string $declaringClass,
        string $propertyName,
        mixed $value,
    ): void {
        $property = ReflectionResource::getClassReflection($declaringClass)->getProperty($propertyName);
        $property->setValue($property->isStatic() ? null : $instance, $value);
    }

    /** @return array<int, string> */
    protected function compiledIds(): array
    {
        return [];
    }

    protected function compiledLifetimeFor(string $id): ?LifetimeEnum
    {
        return null;
    }

    protected function compiledReturn(string $id, mixed &$returned): bool
    {
        return false;
    }

    final protected function compiledScope(): ScopeState
    {
        if (!$this->contextScopesActive || !$this->productionScopes instanceof ProductionScopeStore) {
            return $this->scope;
        }

        return $this->productionScopes->current($this->scope);
    }

    /** @return array<string, mixed> */
    protected function compiledSingletonValues(): array
    {
        return [];
    }

    /** @return array<string, mixed>|null */
    protected function compiledTagged(string $tag): ?array
    {
        return null;
    }

    /** @return iterable<string, callable(): mixed>|null */
    protected function compiledTaggedLazy(string $tag): ?iterable
    {
        return null;
    }

    final protected function constructCompiledScoped(
        ScopeState $scope,
        int $slot,
        string $id,
        callable $resolver,
    ): mixed {
        if (!$this->contextScopesActive) {
            return $scope->resolved[$slot] = $resolver();
        }

        return $this->scopeStore()->constructScoped($scope, $slot, $id, $resolver);
    }

    final protected function dispatchCompiledResolvedHooks(string $id, mixed $value): void
    {
        $this->hookRuntime($id)->getRepository()->dispatchResolvedHooks($id, $value);
    }

    final protected function dispatchCompiledResolvingHooks(string $id): void
    {
        $this->hookRuntime($id)->getRepository()->dispatchResolvingHooks($id);
    }

    /** @param array<string, mixed> $instances */
    final protected function enterScope(string $scope, array $instances = []): static
    {
        $this->validateProductionScopeSeeds($instances);
        $seeds = [];
        foreach ($instances as $id => $value) {
            $slot = $this->slotFor($id);
            if ($slot !== null) {
                $seeds[$slot] = $value;
            }
        }

        $context = $this->productionScopes?->activeContext() ?? ExecutionContext::id();
        if ($context === null) {
            if ($scope === 'root' || $this->scope->contains($scope)) {
                throw new ContainerException("Scope \"{$scope}\" is already active.");
            }
            $this->scope = new ScopeState($scope, $this->scope, $seeds, $instances);
        } else {
            $this->scopeStore()->enter($context, $scope, $seeds, $instances);
            $this->refreshScopeActivity();
        }

        $this->fallback?->enterScope($scope, $instances);

        return $this;
    }

    final protected function fallbackGet(string $id): mixed
    {
        return $this->dynamic()->strictGet($id);
    }

    final protected function fallbackHas(string $id): bool
    {
        return $this->dynamic()->strictHas($id);
    }

    /** @return array<string, mixed> */
    final protected function findByTag(string $tag): array
    {
        $matches = $this->compiledTagged($tag);
        if ($matches === null) {
            $matches = [];
            foreach ($this->taggedIds($tag) as $id) {
                $matches[$id] = $this->get($id);
            }
        }

        if ($this->fallback instanceof ConfigurationContainer) {
            foreach ($this->fallback->findByTag($tag) as $id => $value) {
                $matches[$id] ??= $value;
            }
        }

        return $matches;
    }

    /** @return iterable<string, callable(): mixed> */
    final protected function findByTagLazy(string $tag): iterable
    {
        $compiledIds = $this->taggedIds($tag);
        $compiled = $this->compiledTaggedLazy($tag);
        if ($compiled === null) {
            foreach ($compiledIds as $id) {
                yield $id => fn() => $this->get($id);
            }
        } else {
            yield from $compiled;
        }

        if (!$this->fallback instanceof ConfigurationContainer) {
            return;
        }

        $compiled = array_fill_keys($compiledIds, true);
        foreach ($this->fallback->findByTagLazy($tag) as $id => $resolver) {
            if (!isset($compiled[$id])) {
                yield $id => $resolver;
            }
        }
    }

    protected function freshCompiled(string $class): ?object
    {
        return null;
    }

    protected function freshCompiledInvocation(string $class, string $method, mixed &$result): bool
    {
        return false;
    }

    /** @param array<int|string, mixed> $parameters */
    protected function freshCompiledInvocationWithParameters(
        string $class,
        string $method,
        array $parameters,
        mixed &$result,
    ): bool {
        return false;
    }

    final protected function invokeCompiledRuntimeMethod(
        object $instance,
        string $className,
        string $methodName,
    ): mixed {
        return $this->runtimeIslandResolver()->invokeMethod($instance, $className, $methodName);
    }

    protected function isCompiledDefinition(string $id): bool
    {
        return false;
    }

    protected function isCompiledScopedDefinition(string $id): bool
    {
        return false;
    }

    final protected function isDeoptimized(): bool
    {
        return false;
    }

    final protected function leaveScope(): static
    {
        if (!$this->contextScopesActive || !$this->productionScopes instanceof ProductionScopeStore) {
            $this->leaveSequentialScope(true);

            return $this;
        }

        $this->scope = $this->productionScopes->leaveCurrent(
            $this->scope,
            fn(ScopeState $closing) => $this->beforeScopeClose($closing, true),
        );
        $this->refreshScopeActivity();

        return $this;
    }

    protected function requiresScopeLeaveHook(string $scope): bool
    {
        return false;
    }

    final protected function resolveCompiledSingleton(callable $resolver): mixed
    {
        $owner = ExecutionContext::id() ?? "\0intermix.production.root";
        $this->compiledSingletonResolutionOwners[$owner]
            = ($this->compiledSingletonResolutionOwners[$owner] ?? 0) + 1;
        $this->compiledSingletonResolutionActive = true;

        try {
            return $resolver();
        } finally {
            $remaining = $this->compiledSingletonResolutionOwners[$owner] - 1;
            if ($remaining > 0) {
                $this->compiledSingletonResolutionOwners[$owner] = $remaining;
            } else {
                unset($this->compiledSingletonResolutionOwners[$owner]);
            }
            $this->compiledSingletonResolutionActive = $this->compiledSingletonResolutionOwners !== [];
        }
    }

    final protected function runtimeSelfOrFallback(string $id): mixed
    {
        if ($id === ContainerInterface::class || $id === RuntimeContainerInterface::class) {
            return $this;
        }

        return $this->fallbackGet($id);
    }

    final protected function runtimeSelfOrFallbackHas(string $id): bool
    {
        return $id === ContainerInterface::class
            || $id === RuntimeContainerInterface::class
            || $this->fallbackHas($id);
    }

    /** @return array<int, string> */
    protected function taggedIds(string $tag): array
    {
        return match ($tag) {
            default => [],
        };
    }

    private function assertTaggedScope(?ScopeContext $scopeContext): void
    {
        if ($scopeContext instanceof ScopeContext) {
            $this->scopeStore()->assertCurrent($scopeContext);
        }
    }

    private function beforeScopeClose(ScopeState $scope, bool $synchronizeFallback): void
    {
        if ($this->requiresScopeLeaveHook($scope->name) && !$this->fallback instanceof ConfigurationContainer) {
            throw new ContainerException(
                "Compiled scope '{$scope->name}' requires its runtime scope-leave hook graph.",
            );
        }

        if ($synchronizeFallback) {
            $this->fallback?->leaveScope();
        }
    }

    private function captureFallbackDefinitions(ConfigurationContainer $fallback): void
    {
        $this->fallbackDefinitions = ProductionFallbackState::captureDefinitions(
            $fallback,
            $this->compiledIds(),
            $this->fallbackDefinitions,
        );
    }

    private function currentExecutionScope(): ScopeState
    {
        if (!$this->contextScopesActive || !$this->productionScopes instanceof ProductionScopeStore) {
            return $this->scope;
        }

        return $this->productionScopes->current($this->scope);
    }

    private function detachScopeContext(string $context, ScopeState $scope, bool $synchronizeFallback): void
    {
        if (!$this->productionScopes instanceof ProductionScopeStore) {
            return;
        }

        $this->productionScopes->detach(
            $context,
            $scope,
            fn(ScopeState $closing) => $this->beforeScopeClose($closing, $synchronizeFallback),
        );
        $this->refreshScopeActivity();
    }

    private function dynamic(): ConfigurationContainer
    {
        if ($this->fallback instanceof ConfigurationContainer) {
            return $this->fallback;
        }

        $fallback = new ConfigurationContainer('intermix.production.dynamic.' . spl_object_id($this));
        $this->installFallbackBridges($fallback);
        $this->synchronizeFallbackScopes($fallback);
        $this->fallback = $fallback;

        return $fallback;
    }

    private function hookRuntime(string $id): ConfigurationContainer
    {
        if ($this->fallback instanceof ConfigurationContainer) {
            return $this->fallback;
        }

        throw new ContainerException(
            "Compiled service '$id' requires its runtime lifecycle-hook graph.",
        );
    }

    private function installFallbackBridges(ConfigurationContainer $fallback): void
    {
        foreach ($this->compiledIds() as $id) {
            $fallback->bindFactory(
                $id,
                fn(): mixed => $this->get($id),
                LifetimeEnum::Transient,
            );
        }
    }

    private function leaveSequentialScope(bool $synchronizeFallback): void
    {
        if ($this->scope->name === 'root') {
            return;
        }
        if ($this->scope->attachments > 0) {
            throw new ContainerException('Cannot leave a scope while child execution carriers are still attached.');
        }

        $closing = $this->scope;
        $this->beforeScopeClose($closing, $synchronizeFallback);
        $closing->closed = true;
        $closing->constructing = [];
        $this->scope = $closing->parent ?? new ScopeState('root');
    }

    private function refreshScopeActivity(): void
    {
        $this->contextScopesActive = $this->productionScopes instanceof ProductionScopeStore
            && !$this->productionScopes->isEmpty();
    }

    private function runtimeIslandResolver(): RuntimeIslandResolver
    {
        if (!$this->fallback instanceof ConfigurationContainer) {
            throw new ContainerException(
                'Compiled runtime attribute/method islands require the configured development fallback graph.',
            );
        }

        return $this->runtimeIslands ??= new RuntimeIslandResolver($this->fallback->getRepository());
    }

    private function scopeStore(): ProductionScopeStore
    {
        return $this->productionScopes ??= new ProductionScopeStore();
    }

    private function synchronizeFallbackScopes(ConfigurationContainer $fallback): void
    {
        ProductionFallbackState::synchronizeScopes($fallback, $this->currentExecutionScope());
    }

    /**
     * @param array<int, string> $compiledIds
     * @return iterable<string, mixed>
     */
    private function taggedValues(
        string $tag,
        ?ScopeContext $scopeContext,
        array $compiledIds,
        ?ConfigurationContainer $fallback,
    ): iterable {
        $seen = [];
        foreach ($compiledIds as $id) {
            $this->assertTaggedScope($scopeContext);
            $seen[$id] = true;
            yield $id => $this->get($id);
        }

        if (!$fallback instanceof ConfigurationContainer) {
            return;
        }

        foreach ($fallback->tagged($tag) as $id => $value) {
            if (!isset($seen[$id])) {
                $this->assertTaggedScope($scopeContext);
                yield $id => $value;
            }
        }
    }

    /** @param array<string, mixed> $instances */
    private function validateProductionScopeSeeds(array $instances): void
    {
        foreach (array_keys($instances) as $id) {
            if ($this->isCompiledScopedDefinition($id)) {
                continue;
            }
            if ($this->fallback instanceof ConfigurationContainer) {
                $this->fallback->assertValidScopeSeeds([$id => $instances[$id]]);

                continue;
            }

            throw new ContainerException(
                "Scope seed '$id' must identify a declared scoped entry or input.",
            );
        }
    }
}
