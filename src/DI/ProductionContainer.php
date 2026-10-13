<?php

declare(strict_types=1);

namespace Infocyph\InterMix\DI;

use Infocyph\InterMix\DI\Internal\ConfigurationContainer;
use Infocyph\InterMix\DI\Internal\ExecutionContext;
use Infocyph\InterMix\DI\Internal\ProductionFallbackState;
use Infocyph\InterMix\DI\Internal\ProductionInvocationPlanner;
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

    private bool $lateFallback = false;

    private ?ProductionInvocationPlanner $productionInvocationPlanner = null;

    private ?ProductionScopeStore $productionScopes = null;

    private ?RuntimeIslandResolver $runtimeIslands = null;

    /** @var array<string, true> */
    private array $validatedScopeSeedIds = [];

    /**
     * Initialize root scope state and install bridges for an explicitly supplied fallback.
     */
    public function __construct(private ?ConfigurationContainer $fallback = null)
    {
        $this->scope = new ScopeState('root');
        if ($fallback instanceof ConfigurationContainer) {
            $this->captureFallbackDefinitions($fallback);
            $this->installFallbackBridges($fallback);
        }
    }

    /**
     * Map a compiled identifier to its immutable slot, or null when it has no native plan.
     */
    abstract protected function slotFor(string $id): ?int;

    /**
     * Capture an active native scope and its matching fallback scope for in-process propagation.
     */
    final public function captureScopeContext(): ScopeContext
    {
        $fallbackContext = $this->fallback === null ? null : $this->dynamic()->captureScopeContext();
        [$captured, $scope] = $this->scopeStore()->capture($this->scope, $fallbackContext);
        $this->scope = $scope;
        $this->refreshScopeActivity();

        return $captured;
    }

    /**
     * Use a supported compiled invocation recipe, otherwise delegate to the runtime invoker.
     *
     * @param array<int|string, mixed> $arguments
     */
    final public function invoke(callable $callable, array $arguments = []): mixed
    {
        $result = null;
        if ($this->compiledInvocationPlanner()?->invoke($callable, $arguments, $this, $result) === true) {
            return $result;
        }

        return $this->dynamic()->invoke($callable, $arguments);
    }

    /**
     * Construct a fresh instance through the runtime invoker without reusing lifetime caches.
     *
     * @param array<int|string, mixed> $arguments
     */
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
        $failures = [];
        $failureCount = 0;

        if (!$this->contextScopesActive || !$this->productionScopes instanceof ProductionScopeStore) {
            while ($this->scope->name !== 'root') {
                try {
                    $this->leaveSequentialScope(true);
                } catch (ScopeCleanupException $failure) {
                    $this->appendCleanupFailure($failure, $failures, $failureCount);
                }
            }
            $this->throwCleanupFailures($failures, $failureCount);

            return;
        }

        $this->scope = $this->productionScopes->resetCurrent(
            $this->scope,
            fn(ScopeState $closing) => $this->beforeScopeClose($closing, true),
            $failures,
            $failureCount,
        );

        try {
            $this->fallback?->resetCurrentExecutionScope();
        } catch (ScopeCleanupException $failure) {
            $this->appendCleanupFailure($failure, $failures, $failureCount);
        }
        $this->refreshScopeActivity();
        $this->throwCleanupFailures($failures, $failureCount);
    }

    /**
     * Yield tagged values lazily and enforce the originating scope while iterating.
     *
     * @return iterable<string, mixed>
     */
    final public function tagged(string $tag): iterable
    {
        $scopeContext = $this->compiledScope()->name === 'root'
            ? null
            : $this->captureScopeContext();

        return $this->taggedValues(
            $tag,
            $scopeContext,
            $this->taggedIds($tag),
            $this->fallback,
        );
    }

    /**
     * Validate seeds, execute work and close the scope even when the callback fails.
     *
     * @param array<string, mixed> $instances
     */
    final public function withinScope(string $scope, callable $callback, array $instances = []): mixed
    {
        $this->enterScope($scope, $instances);

        try {
            $result = $callback($this);
        } catch (Throwable $throwable) {
            $this->leaveScopeAfter($throwable);

            throw $throwable;
        }

        $this->leaveScope();

        return $result;
    }

    /**
     * Attach a container-owned native and fallback scope for the duration of the callback.
     */
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

    /**
     * Delegate an unsupported property attribute to the shared runtime injection algorithm.
     */
    final protected function applyCompiledRuntimePropertyAttribute(
        object $instance,
        string $declaringClass,
        string $propertyName,
    ): void {
        $this->runtimeIslandResolver()->applyAttributedProperty($instance, $declaringClass, $propertyName);
    }

    /**
     * Reject scoped dependency capture by singleton construction on the same carrier.
     */
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

    /**
     * Assign a reflected property, including inaccessible or static properties.
     *
     * @param class-string $declaringClass
     */
    final protected function assignCompiledRuntimeProperty(
        object $instance,
        string $declaringClass,
        string $propertyName,
        mixed $value,
    ): void {
        $property = ReflectionResource::getClassReflection($declaringClass)->getProperty($propertyName);
        $property->setValue($property->isStatic() ? null : $instance, $value);
    }

    /**
     * Declare identifiers with native slots; generated artifacts override the empty default.
     *
     * @return array<int, string>
     */
    protected function compiledIds(): array
    {
        return [];
    }

    /**
     * Expose a native entry's lifetime, or null when no compiled plan exists.
     */
    protected function compiledLifetimeFor(string $id): ?LifetimeEnum
    {
        return null;
    }

    /**
     * Try a compiled invocation return, distinguishing an unsupported recipe from a null result.
     */
    protected function compiledReturn(string $id, mixed &$returned): bool
    {
        return false;
    }

    /**
     * Select the current carrier's native scope while retaining the sequential fast path.
     */
    final protected function compiledScope(): ScopeState
    {
        if (!$this->contextScopesActive || !$this->productionScopes instanceof ProductionScopeStore) {
            return $this->scope;
        }

        return $this->productionScopes->current($this->scope);
    }

    /**
     * Expose already constructed native singletons for fallback synchronization.
     *
     * @return array<string, mixed>
     */
    protected function compiledSingletonValues(): array
    {
        return [];
    }

    /**
     * Try native eager tag resolution; null requests generic tag dispatch.
     *
     * @return array<string, mixed>|null
     */
    protected function compiledTagged(string $tag): ?array
    {
        return null;
    }

    /**
     * Try native deferred tag resolution; null requests generic tag dispatch.
     *
     * @return iterable<string, callable(): mixed>|null
     */
    protected function compiledTaggedLazy(string $tag): ?iterable
    {
        return null;
    }

    /**
     * Apply frozen injection and conversion policies when materializing a late runtime invoker.
     */
    protected function configureRuntimeInvoker(ConfigurationContainer $runtime): void {}

    /**
     * Cache a scoped result and prevent conflicting construction by attached carriers.
     */
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

    /**
     * Run matching fallback hooks after successful native construction.
     */
    final protected function dispatchCompiledResolvedHooks(string $id, mixed $value): void
    {
        $this->hookRuntime($id)->getRepository()->dispatchResolvedHooks($id, $value);
    }

    /**
     * Run matching fallback hooks before native construction.
     */
    final protected function dispatchCompiledResolvingHooks(string $id): void
    {
        $this->hookRuntime($id)->getRepository()->dispatchResolvingHooks($id);
    }

    /**
     * Validate declared seeds, map native slots and enter matching native and fallback scopes.
     *
     * @param array<string, mixed> $instances
     */
    final protected function enterScope(string $scope, array $instances = []): static
    {
        $seeds = [];
        $fallbackInstances = $instances;
        foreach ($instances as $id => $value) {
            $id = (string) $id;
            if (!isset($this->validatedScopeSeedIds[$id])) {
                $this->validateProductionScopeSeed($id, $value);
            }

            $slot = $this->slotFor($id);
            if ($slot !== null) {
                $seeds[$slot] = $value;
                unset($fallbackInstances[$id]);
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

        $this->fallback?->enterScope($scope, $fallbackInstances);

        return $this;
    }

    /**
     * Resolve through the fallback's declaration contract, without legacy class discovery.
     */
    final protected function fallbackGet(string $id): mixed
    {
        return $this->dynamic()->strictGet($id);
    }

    /**
     * Check fallback declaration presence without constructing or discovering classes.
     */
    final protected function fallbackHas(string $id): bool
    {
        return $this->dynamic()->strictHas($id);
    }

    /**
     * Combine native and fallback tagged values, preferring native entries with matching
     * identifiers.
     *
     * @return array<string, mixed>
     */
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

    /**
     * Yield native and fallback resolvers once per identifier.
     *
     * @return iterable<string, callable(): mixed>
     */
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

    /**
     * Try a fresh native construction recipe, returning null when unsupported.
     */
    protected function freshCompiled(string $class): ?object
    {
        return null;
    }

    /**
     * Try a fresh native method recipe, preserving null as a successful result.
     */
    protected function freshCompiledInvocation(string $class, string $method, mixed &$result): bool
    {
        return false;
    }

    /**
     * Try a fresh native method recipe with explicit runtime argument overrides.
     *
     * @param array<int|string, mixed> $parameters
     */
    protected function freshCompiledInvocationWithParameters(
        string $class,
        string $method,
        array $parameters,
        mixed &$result,
    ): bool {
        return false;
    }

    /**
     * Execute a method requiring runtime reflection through the shared injection island.
     */
    final protected function invokeCompiledRuntimeMethod(
        object $instance,
        string $className,
        string $methodName,
    ): mixed {
        return $this->runtimeIslandResolver()->invokeMethod($instance, $className, $methodName);
    }

    /**
     * Identify native declarations for bridge and seed validation.
     */
    protected function isCompiledDefinition(string $id): bool
    {
        return false;
    }

    /**
     * Identify native scoped declarations eligible for execution-owned seeds.
     */
    protected function isCompiledScopedDefinition(string $id): bool
    {
        return false;
    }

    /**
     * Report the fixed native runtime mode; dynamic bridges do not deoptimize the artifact.
     */
    final protected function isDeoptimized(): bool
    {
        return false;
    }

    /**
     * Close the current native scope, run cleanup and restore its parent scope.
     */
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

    /**
     * Identify scope names whose generated cleanup contract requires fallback hooks.
     */
    protected function requiresScopeLeaveHook(string $scope): bool
    {
        return false;
    }

    /**
     * Track singleton construction per carrier and always release the captive-dependency guard.
     */
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

    /**
     * Return this runtime for container interfaces, otherwise resolve a fallback declaration.
     */
    final protected function runtimeSelfOrFallback(string $id): mixed
    {
        if ($id === ContainerInterface::class || $id === RuntimeContainerInterface::class) {
            return $this;
        }

        return $this->fallbackGet($id);
    }

    /**
     * Recognize runtime container interfaces before consulting fallback declarations.
     */
    final protected function runtimeSelfOrFallbackHas(string $id): bool
    {
        return $id === ContainerInterface::class
            || $id === RuntimeContainerInterface::class
            || $this->fallbackHas($id);
    }

    /**
     * Expose native identifiers associated with a tag; unknown tags use the empty default.
     *
     * @return array<int, string>
     */
    protected function taggedIds(string $tag): array
    {
        return match ($tag) {
            default => [],
        };
    }

    /**
     * @param list<Throwable> $failures
     */
    private function appendCleanupFailure(
        ScopeCleanupException $failure,
        array &$failures,
        int &$failureCount,
    ): void {
        $failureCount += $failure->cleanupFailureCount;
        array_push($failures, ...array_slice($failure->cleanupFailures, 0, 32 - count($failures)));
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

    private function compiledInvocationPlanner(): ?ProductionInvocationPlanner
    {
        if (!$this->fallback instanceof ConfigurationContainer) {
            return null;
        }

        return $this->productionInvocationPlanner ??= new ProductionInvocationPlanner(
            $this->fallback->getRepository(),
            $this->compiledIds(),
        );
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
        if (!$this->fallback instanceof ConfigurationContainer) {
            $fallback = new ConfigurationContainer('intermix.production.dynamic.' . spl_object_id($this));
            $this->configureRuntimeInvoker($fallback);
            $this->installFallbackBridges($fallback);
            $this->fallback = $fallback;
            $this->lateFallback = true;
        }
        if ($this->lateFallback) {
            ProductionFallbackState::synchronizeScopes($this->fallback, $this->compiledScope(), $this->compiledIds());
        }

        return $this->fallback;
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
                $this->compiledLifetimeFor($id) === LifetimeEnum::Scoped ? LifetimeEnum::Scoped : LifetimeEnum::Transient,
            );
        }
    }

    private function leaveScopeAfter(Throwable $workFailure): void
    {
        try {
            $this->leaveScope();
        } catch (ScopeCleanupException $cleanupFailure) {
            throw new ScopeCleanupException(
                $cleanupFailure->cleanupFailures,
                $cleanupFailure->cleanupFailureCount,
                $workFailure,
            );
        }
    }

    private function leaveSequentialScope(bool $synchronizeFallback): void
    {
        if ($this->scope->name === 'root') {
            return;
        }
        if ($this->scope->attachments > 0) {
            $this->scope->draining = true;

            throw new ContainerException('Cannot leave a scope while child execution carriers are still attached.');
        }

        $closing = $this->scope;
        $parent = $closing->parent ?? new ScopeState('root');

        try {
            $this->beforeScopeClose($closing, $synchronizeFallback);
        } finally {
            $closing->close();
            $this->scope = $parent;
        }
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

    /** @param list<Throwable> $failures */
    private function throwCleanupFailures(array $failures, int $failureCount): void
    {
        if ($failureCount > 0) {
            throw new ScopeCleanupException($failures, $failureCount);
        }
    }

    private function validateProductionScopeSeed(string $id, mixed $instance): void
    {
        if ($this->isCompiledScopedDefinition($id)) {
            $this->validatedScopeSeedIds[$id] = true;

            return;
        }
        if ($this->fallback instanceof ConfigurationContainer && $this->slotFor($id) === null) {
            $this->fallback->assertValidScopeSeeds([$id => $instance]);
            $this->validatedScopeSeedIds[$id] = true;

            return;
        }

        throw new ContainerException(
            "Scope seed '$id' must identify a declared scoped entry or input.",
        );
    }
}
