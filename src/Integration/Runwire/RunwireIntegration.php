<?php

declare(strict_types=1);

namespace Infocyph\InterMix\Integration\Runwire;

use Closure;
use Infocyph\CacheLayer\Integration\Runwire\RunwireIntegration as CacheLayerRunwireIntegration;
use Infocyph\InterMix\DI\ContainerBuilder;
use Infocyph\InterMix\DI\RuntimeContainerInterface;
use Infocyph\InterMix\DI\ScopeContext;
use Infocyph\Runwire\Coroutine\CoroutineScope;
use Infocyph\Runwire\Coroutine\TaskLocal;
use Infocyph\Runwire\RequestContext;
use Infocyph\Runwire\Runtime\Enum\RuntimeCapability;
use Infocyph\Runwire\RuntimeContext;
use LogicException;

/**
 * Optional host-owned Runwire 2.1 bridge for InterMix request/task scopes.
 *
 * The bridge never creates a Runwire runtime, drives an event loop, completes a
 * request, or closes a borrowed coroutine scope. Those lifecycles stay with the host.
 */
final class RunwireIntegration
{
    private int $activeBoundaries = 0;

    private ?RuntimeContext $runtimeContext = null;

    private readonly TaskLocal $scopeContextLocal;

    public function __construct(
        private readonly RuntimeContainerInterface $container,
        private readonly bool $ownsCacheLayerLifecycle = false,
    ) {
        $this->scopeContextLocal = new TaskLocal();
    }

    public static function registerInputs(ContainerBuilder $builder): ContainerBuilder
    {
        return $builder
            ->input(RuntimeContext::class)
            ->input(RequestContext::class)
            ->input(CoroutineScope::class);
    }

    public function bind(RuntimeContext $runtime): void
    {
        if ($this->runtimeContext === $runtime) {
            return;
        }
        if ($this->activeBoundaries > 0) {
            throw new LogicException('Runwire runtime cannot change while InterMix boundaries are active.');
        }
        if ($this->runtimeContext !== null) {
            throw new LogicException('InterMix is already bound to a different Runwire runtime.');
        }

        $this->bindCacheLayer($runtime);
        $this->runtimeContext = $runtime;
    }

    public function checkpoint(?CoroutineScope $scope = null): void
    {
        $runtime = $this->requireRuntime();
        if ($scope === null || !$runtime->supports(RuntimeCapability::RUNWIRE_COROUTINES)) {
            return;
        }

        $scope->cancellation()->throwIfCancelled();
    }

    public function release(?RuntimeContext $runtime = null): void
    {
        $bound = $this->runtimeContext;
        if ($bound === null || ($runtime !== null && $bound !== $runtime)) {
            return;
        }
        if ($this->activeBoundaries > 0) {
            throw new LogicException('Runwire runtime cannot be released while InterMix boundaries are active.');
        }

        if ($this->ownsCacheLayerLifecycle && class_exists(CacheLayerRunwireIntegration::class)) {
            CacheLayerRunwireIntegration::release($bound);
        }
        $this->runtimeContext = null;
    }

    public function runtime(): ?RuntimeContext
    {
        return $this->runtimeContext;
    }

    public function supports(RuntimeCapability $capability): bool
    {
        return $this->runtimeContext?->supports($capability) ?? false;
    }

    /**
     * @param callable(RuntimeContainerInterface): mixed $callback
     * @param array<string, mixed> $instances
     */
    public function withinRequest(
        RequestContext $request,
        ?CoroutineScope $scope,
        callable $callback,
        array $instances = [],
    ): mixed {
        $runtime = $this->validateBoundary($request, $scope);
        $seeds = $this->requestSeeds($runtime, $request, $scope, $instances);

        ++$this->activeBoundaries;
        try {
            return $this->container->withinScope(
                'runwire.request',
                function (RuntimeContainerInterface $container) use (
                    $request,
                    $scope,
                    $callback,
                ): mixed {
                    return $this->shareBoundary(
                        $container->captureScopeContext(),
                        $request,
                        $scope,
                        $callback,
                        $container,
                    );
                },
                $seeds,
            );
        } finally {
            --$this->activeBoundaries;
        }
    }

    /**
     * Attach a borrowed live InterMix scope handle to the current Runwire boundary.
     *
     * @param callable(RuntimeContainerInterface): mixed $callback
     */
    public function withinScopeContext(
        ScopeContext $context,
        ?RequestContext $request,
        ?CoroutineScope $scope,
        callable $callback,
    ): mixed {
        $this->validateBoundary($request, $scope);

        ++$this->activeBoundaries;
        try {
            return $this->container->withinScopeContext(
                $context,
                fn(RuntimeContainerInterface $container): mixed => $this->shareBoundary(
                    $context,
                    $request,
                    $scope,
                    $callback,
                    $container,
                ),
            );
        } finally {
            --$this->activeBoundaries;
        }
    }

    /**
     * Wrap child work at the host's Runwire spawn boundary.
     *
     * @param callable(RuntimeContainerInterface): mixed $callback
     * @return Closure(): mixed
     */
    public function wrapChild(
        ?RequestContext $request,
        CoroutineScope $scope,
        callable $callback,
    ): Closure {
        return function () use ($request, $scope, $callback): mixed {
            $context = $scope->local($this->scopeContextLocal);
            if (!$context instanceof ScopeContext) {
                throw new LogicException('Runwire child has no inherited InterMix scope context.');
            }

            return $this->withinScopeContext($context, $request, $scope, $callback);
        };
    }

    private function bindCacheLayer(RuntimeContext $runtime): void
    {
        if (!class_exists(CacheLayerRunwireIntegration::class)) {
            return;
        }

        $bound = CacheLayerRunwireIntegration::runtime();
        if ($bound !== null && $bound !== $runtime) {
            throw new LogicException('CacheLayer is bound to a different Runwire runtime.');
        }
        if ($this->ownsCacheLayerLifecycle && $bound === null) {
            CacheLayerRunwireIntegration::bind($runtime);
        }
    }

    /** @param callable(): mixed $callback */
    private function forwardCacheLayer(
        RuntimeContext $runtime,
        ?RequestContext $request,
        ?CoroutineScope $scope,
        callable $callback,
    ): mixed {
        if (!class_exists(CacheLayerRunwireIntegration::class)) {
            return $callback();
        }

        $bound = CacheLayerRunwireIntegration::runtime();
        if ($bound === null) {
            return $callback();
        }
        if ($bound !== $runtime) {
            throw new LogicException('CacheLayer is bound to a different Runwire runtime.');
        }

        return CacheLayerRunwireIntegration::share($request, $scope, $callback);
    }

    private function requireRuntime(): RuntimeContext
    {
        return $this->runtimeContext
            ?? throw new LogicException('InterMix Runwire integration is not bound to a runtime.');
    }

    /**
     * @param array<string, mixed> $instances
     * @return array<string, mixed>
     */
    private function requestSeeds(
        RuntimeContext $runtime,
        RequestContext $request,
        ?CoroutineScope $scope,
        array $instances,
    ): array {
        foreach ([RuntimeContext::class, RequestContext::class, CoroutineScope::class] as $reserved) {
            if (array_key_exists($reserved, $instances)) {
                throw new LogicException("Runwire input '$reserved' is reserved by the integration.");
            }
        }

        $seeds = $instances;
        $seeds[RuntimeContext::class] = $runtime;
        $seeds[RequestContext::class] = $request;
        if ($scope !== null) {
            $seeds[CoroutineScope::class] = $scope;
        }

        return $seeds;
    }

    /**
     * @param callable(RuntimeContainerInterface): mixed $callback
     */
    private function shareBoundary(
        ScopeContext $context,
        ?RequestContext $request,
        ?CoroutineScope $scope,
        callable $callback,
        RuntimeContainerInterface $container,
    ): mixed {
        $runtime = $this->requireRuntime();
        $hadLocal = $scope?->hasLocal($this->scopeContextLocal) ?? false;
        $previous = $hadLocal ? $scope?->local($this->scopeContextLocal) : null;
        $scope?->setLocal($this->scopeContextLocal, $context);

        try {
            return $this->forwardCacheLayer(
                $runtime,
                $request,
                $scope,
                static fn(): mixed => $callback($container),
            );
        } finally {
            if ($scope !== null) {
                if ($hadLocal) {
                    $scope->setLocal($this->scopeContextLocal, $previous);
                } else {
                    $scope->removeLocal($this->scopeContextLocal);
                }
            }
        }
    }

    private function validateBoundary(
        ?RequestContext $request,
        ?CoroutineScope $scope,
    ): RuntimeContext {
        $runtime = $this->requireRuntime();
        if ($request !== null) {
            if ($request->completed()) {
                throw new LogicException('Completed Runwire request context cannot enter InterMix.');
            }
            if ($request->runtime() !== $runtime) {
                throw new LogicException('Runwire request context is bound to a different runtime.');
            }
        }
        if ($scope !== null) {
            if (!$runtime->supports(RuntimeCapability::RUNWIRE_COROUTINES)) {
                throw new LogicException(
                    'Runwire coroutine scope supplied without RUNWIRE_COROUTINES capability.',
                );
            }
            $scope->cancellation()->throwIfCancelled();
        }

        return $runtime;
    }
}
