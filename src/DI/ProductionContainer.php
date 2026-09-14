<?php

declare(strict_types=1);

namespace Infocyph\InterMix\DI;

use Closure;
use Infocyph\InterMix\DI\Internal\ExecutionContext;
use Infocyph\InterMix\DI\Internal\ProductionFallbackState;
use Infocyph\InterMix\DI\Internal\ProductionScopeStore;
use Infocyph\InterMix\DI\Internal\ProductionSpecResolver;
use Infocyph\InterMix\DI\Internal\RuntimeIslandResolver;
use Infocyph\InterMix\DI\Internal\ScopeState;
use Infocyph\InterMix\DI\Support\LifetimeEnum;
use Infocyph\InterMix\Exceptions\ContainerException;
use Infocyph\InterMix\Internal\ReflectionResource;
use Psr\Container\ContainerInterface;

abstract class ProductionContainer implements ContainerInterface
{
    protected bool $contextScopesActive = false;

    protected ScopeState $scope;

    private bool $deoptimizationReady = false;

    private bool $deoptimized = false;

    private ?Container $fallback;

    /** @var array<string, mixed> */
    private array $fallbackBridgeDefinitions = [];

    /**
     * @var array<string, array{exists: bool, definition: mixed, lifetime: LifetimeEnum, tags: array<int, string>}>
     */
    private array $fallbackDefinitions = [];

    private ?ProductionScopeStore $productionScopes = null;

    private ?RuntimeIslandResolver $runtimeIslands = null;

    public function __construct(?Container $fallback = null)
    {
        $this->scope = new ScopeState('root');
        $this->fallback = $fallback;
        if ($fallback instanceof Container) {
            $this->captureFallbackDefinitions($fallback);
            $this->installFallbackBridges($fallback);
            $this->deoptimizationReady = true;
        }
    }

    abstract protected function slotFor(string $id): ?int;

    /** @internal */
    final public function attachFallback(Container $fallback): void
    {
        if ($this->fallback !== $fallback) {
            $this->fallbackDefinitions = [];
            $this->runtimeIslands = null;
        }

        $this->captureFallbackDefinitions($fallback);
        $this->installFallbackBridges($fallback);
        $this->fallback = $fallback;
        $this->synchronizeFallbackScopes($fallback);
        $this->deoptimizationReady = true;
    }

    /** @throws ContainerException|\ReflectionException|\Psr\Cache\InvalidArgumentException */
    final public function call(string|Closure|callable $classOrClosure, string|bool|null $method = null): mixed
    {
        if (is_string($classOrClosure) && $this->isCompiledDefinition($classOrClosure)) {
            return $this->callCompiledDefinition($classOrClosure, $method);
        }

        return $this->dynamic()->call($classOrClosure, $method);
    }

    final public function captureScopeContext(): ScopeContext
    {
        $fallbackContext = $this->fallback?->captureScopeContext();
        [$captured, $scope] = $this->scopeStore()->capture($this->scope, $fallbackContext);
        $this->scope = $scope;
        $this->refreshScopeActivity();

        return $captured;
    }

    /**
     * Switch all production resolution to the original dynamic graph while
     * preserving compiled singleton/scoped identities already materialized.
     */
    final public function deoptimize(): void
    {
        if ($this->deoptimized) {
            return;
        }
        if (!$this->deoptimizationReady || !$this->fallback instanceof Container) {
            throw new ContainerException(
                'Production deoptimization requires a configured development fallback graph.',
            );
        }

        $this->deoptimized = true;
        $overridden = $this->restoreFallbackDefinitions($this->fallback);
        $this->transferCompiledState($this->fallback, $overridden);
    }

    /** @param array<string, mixed> $instances */
    final public function enterScope(string $scope, array $instances = []): static
    {
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

    /** @return array<string, mixed> */
    final public function findByTag(string $tag): array
    {
        if ($this->deoptimized) {
            return $this->dynamic()->findByTag($tag);
        }

        $matches = $this->compiledTagged($tag);
        if ($matches === null) {
            $matches = [];
            foreach ($this->taggedIds($tag) as $id) {
                $matches[$id] = $this->get($id);
            }
        }

        if ($this->fallback instanceof Container) {
            foreach ($this->fallback->findByTag($tag) as $id => $value) {
                $matches[$id] ??= $value;
            }
        }

        return $matches;
    }

    /** @return iterable<string, callable(): mixed> */
    final public function findByTagLazy(string $tag): iterable
    {
        if ($this->deoptimized) {
            yield from $this->dynamic()->findByTagLazy($tag);

            return;
        }

        $compiledIds = $this->taggedIds($tag);
        $compiled = $this->compiledTaggedLazy($tag);
        if ($compiled === null) {
            foreach ($compiledIds as $id) {
                yield $id => fn() => $this->get($id);
            }
        } else {
            yield from $compiled;
        }

        if (!$this->fallback instanceof Container) {
            return;
        }

        $compiled = array_fill_keys($compiledIds, true);
        foreach ($this->fallback->findByTagLazy($tag) as $id => $resolver) {
            if (!isset($compiled[$id])) {
                yield $id => $resolver;
            }
        }
    }

    /** @throws ContainerException|\ReflectionException|\Psr\Cache\InvalidArgumentException */
    final public function getReturn(string $id): mixed
    {
        if ($this->deoptimized) {
            return $this->dynamic()->getReturn($id);
        }
        if ($this->isCompiledDefinition($id)) {
            $service = $this->get($id);
            $returned = null;

            return $this->compiledReturn($id, $returned) ? $returned : $service;
        }

        return $this->dynamic()->getReturn($id);
    }

    final public function leaveScope(): static
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

    /** @throws ContainerException|\ReflectionException */
    final public function make(string $class, string|bool $method = false): mixed
    {
        if (!$this->deoptimized) {
            if ($method === false) {
                $fresh = $this->freshCompiled($class);
                if ($fresh !== null) {
                    return $fresh;
                }
            } elseif (is_string($method)) {
                $result = null;
                if ($this->freshCompiledInvocation($class, $method, $result)) {
                    return $result;
                }
            }
        }

        return $this->dynamic()->make($class, $method);
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

    /**
     * @param string|array<array-key, mixed>|Closure|callable|null $spec
     * @param array<int|string, mixed> $parameters
     */
    final public function resolveNow(
        string|Closure|callable|array|null $spec,
        array $parameters = [],
    ): mixed {
        if ($spec === null) {
            return $this;
        }

        $result = null;
        if (!$this->deoptimized && $this->resolveCompiledNow($spec, $parameters, $result)) {
            return $result;
        }

        return ProductionSpecResolver::resolveDynamic($this->dynamic(), $spec, $parameters);
    }

    /** @return iterable<string, callable(): mixed> */
    final public function tagged(string $tag): iterable
    {
        return $this->findByTagLazy($tag);
    }

    /** @param array<string, mixed> $instances */
    final public function withinScope(string $scope, callable $callback, array $instances = []): mixed
    {
        $this->enterScope($scope, $instances);

        try {
            return $callback($this);
        } finally {
            $this->leaveScope();
        }
    }

    final public function withinScopeContext(ScopeContext $scopeContext, callable $callback): mixed
    {
        [$scope, $fallbackContext] = $this->scopeStore()->unwrap($scopeContext);
        $context = $this->scopeStore()->attach($scope, $this->scope);
        $this->refreshScopeActivity();

        if ($this->fallback instanceof Container && $fallbackContext instanceof ScopeContext) {
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

    final protected function fallbackGet(string $id): mixed
    {
        return $this->dynamic()->get($id);
    }

    final protected function fallbackHas(string $id): bool
    {
        return $this->dynamic()->has($id);
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

    final protected function isDeoptimized(): bool
    {
        return $this->deoptimized;
    }

    protected function requiresScopeLeaveHook(string $scope): bool
    {
        return false;
    }

    /** @return array<int, string> */
    protected function taggedIds(string $tag): array
    {
        return match ($tag) {
            default => [],
        };
    }

    private function beforeScopeClose(ScopeState $scope, bool $synchronizeFallback): void
    {
        if ($this->requiresScopeLeaveHook($scope->name) && !$this->fallback instanceof Container) {
            throw new ContainerException(
                "Compiled scope '{$scope->name}' requires its runtime scope-leave hook graph.",
            );
        }

        if ($synchronizeFallback) {
            $this->fallback?->leaveScope();
        }
    }

    private function callCompiledDefinition(string $id, string|bool|null $method): mixed
    {
        $service = $this->get($id);
        if (!is_string($method) || $method === '') {
            return $service;
        }
        if (!is_object($service) || !method_exists($service, $method)) {
            throw new ContainerException("Method {$id}::{$method}() does not exist.");
        }

        return $service->{$method}();
    }

    private function captureFallbackDefinitions(Container $fallback): void
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

    private function dynamic(): Container
    {
        if ($this->fallback instanceof Container) {
            return $this->fallback;
        }

        $fallback = new Container('intermix.production.dynamic.' . spl_object_id($this));
        $this->installFallbackBridges($fallback);
        $this->synchronizeFallbackScopes($fallback);
        $this->fallback = $fallback;

        return $fallback;
    }

    private function hookRuntime(string $id): Container
    {
        if ($this->fallback instanceof Container) {
            return $this->fallback;
        }

        throw new ContainerException(
            "Compiled service '$id' requires its runtime lifecycle-hook graph.",
        );
    }

    private function installFallbackBridges(Container $fallback): void
    {
        foreach ($this->compiledIds() as $id) {
            $fallback->bindFactory(
                $id,
                fn(): mixed => $this->get($id),
                LifetimeEnum::Transient,
            );
            $this->fallbackBridgeDefinitions[$id] = $fallback->getRepository()->getFunctionDefinition($id);
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

    /**
     * @param string|array<array-key, mixed>|Closure|callable $spec
     * @param array<int|string, mixed> $parameters
     */
    private function resolveCompiledNow(
        string|Closure|callable|array $spec,
        array $parameters,
        mixed &$result,
    ): bool {
        if ($parameters === []) {
            return $this->resolveFreshCompiledSpec($spec, $result);
        }
        if (!is_array($spec)
            || count($spec) !== 2
            || !array_is_list($spec)
            || !isset($spec[0], $spec[1])
            || !is_string($spec[0])
            || !is_string($spec[1])
        ) {
            return false;
        }

        return $this->freshCompiledInvocationWithParameters(
            $spec[0],
            $spec[1],
            $parameters,
            $result,
        );
    }

    /** @param string|array<array-key, mixed>|Closure|callable $spec */
    private function resolveFreshCompiledSpec(string|Closure|callable|array $spec, mixed &$result): bool
    {
        if (is_string($spec)) {
            $fresh = $this->freshCompiled($spec);
            if ($fresh === null) {
                return false;
            }

            $result = $fresh;

            return true;
        }
        if (!is_array($spec)
            || count($spec) !== 2
            || !isset($spec[0], $spec[1])
            || !is_string($spec[0])
            || !is_string($spec[1])
        ) {
            return false;
        }

        return $this->freshCompiledInvocation($spec[0], $spec[1], $result);
    }

    /** @return array<string, true> */
    private function restoreFallbackDefinitions(Container $fallback): array
    {
        return ProductionFallbackState::restoreDefinitions(
            $fallback,
            $this->fallbackDefinitions,
            $this->fallbackBridgeDefinitions,
        );
    }

    private function runtimeIslandResolver(): RuntimeIslandResolver
    {
        if (!$this->fallback instanceof Container) {
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

    private function synchronizeFallbackScopes(Container $fallback): void
    {
        ProductionFallbackState::synchronizeScopes($fallback, $this->currentExecutionScope());
    }

    /** @param array<string, true> $overridden */
    private function transferCompiledState(?Container $fallback, array $overridden = []): void
    {
        if (!$fallback instanceof Container) {
            return;
        }

        ProductionFallbackState::transferCompiledState(
            $fallback,
            $overridden,
            $this->compiledSingletonValues(),
            $this->compiledIds(),
            $this->currentExecutionScope(),
        );
    }
}
