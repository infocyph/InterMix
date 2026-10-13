<?php

declare(strict_types=1);

namespace Infocyph\InterMix\DI\Managers;

use Closure;
use Infocyph\InterMix\DI\Container;
use Infocyph\InterMix\DI\Internal\ClassResolution;
use Infocyph\InterMix\DI\Internal\ContainerAccess;
use Infocyph\InterMix\DI\Internal\ExecutionContext;
use Infocyph\InterMix\DI\Resolver\ConcurrentRepository;
use Infocyph\InterMix\DI\Resolver\Repository;
use Infocyph\InterMix\DI\Support\AliasDefinition;
use Infocyph\InterMix\DI\Support\LifetimeEnum;
use Infocyph\InterMix\DI\Support\TraceLevelEnum;
use Infocyph\InterMix\Exceptions\ContainerException;
use Infocyph\InterMix\Exceptions\NotFoundException;
use Psr\Cache\InvalidArgumentException;
use ReflectionException;

/**
 * Handles get(), has(), getReturn(), call(), make().
 */
class InvocationManager
{
    /** @var array<int|string, list<LifetimeEnum>> */
    private array $lifetimeStacks = [];

    private bool $singletonResolutionActive = false;

    /**
     * Associate invocation and lifetime tracking with one container and its repository.
     */
    public function __construct(
        protected Repository $repository,
        protected Container $container,
    ) {}

    /**
     * Reject singleton capture of scoped dependencies on the current execution carrier.
     *
     * @internal
     */
    public function assertScopedResolutionAllowed(string $id): void
    {
        if (!$this->singletonResolutionActive) {
            return;
        }

        $stack = $this->lifetimeStacks[$this->resolutionOwner()] ?? [];
        if (in_array(LifetimeEnum::Singleton, $stack, true)) {
            throw new ContainerException(
                "Singleton construction cannot capture scoped entry '$id'.",
            );
        }
    }

    /**
     * Dispatch legacy definitions, callable resources or class methods through dependency
     * injection.
     *
     * @throws ContainerException|ReflectionException|InvalidArgumentException
     */
    public function call(string|Closure|callable $classOrClosure, string|bool|null $method = null): mixed
    {
        if (is_string($classOrClosure) && $this->repository->hasFunctionReference($classOrClosure)) {
            return $this->callDefinition($classOrClosure, $method);
        }

        if ($classOrClosure instanceof Closure || is_callable($classOrClosure)) {
            return $this->callCallable($classOrClosure, $method);
        }

        if ($this->repository->hasClosureResource($classOrClosure)) {
            return $this->callClosureResource($classOrClosure, $method);
        }

        return $this->callClass($classOrClosure, $method);
    }

    /**
     * Resolve native declarations with seed, lifetime-cache and captive-dependency enforcement.
     *
     * @throws ContainerException|InvalidArgumentException|ReflectionException
     */
    public function get(string $id): mixed
    {
        $resolved = $this->repository->getResolvedSingletonEntry($id);
        if ($resolved !== null || $this->repository->hasResolvedSingleton($id)) {
            return $resolved;
        }

        $seed = null;
        if ($this->repository->findScopeSeed($id, $seed)) {
            $this->assertScopedResolutionAllowed($id);

            return $seed;
        }

        $alias = null;
        $lifetime = $this->repository->getDefinitionLifetime($id, $alias);
        $scope = 'root';
        if ($lifetime === LifetimeEnum::Scoped) {
            $this->assertScopedResolutionAllowed($id);
            $found = $this->repository instanceof ConcurrentRepository
                ? $this->repository->findCurrentResolvedScoped($id, $scope, $resolved)
                : $this->findResolvedScoped($id, $scope, $resolved);
            if ($found && $scope !== 'root') {
                return $this->repository->fetchInstanceOrValue($resolved);
            }
        }

        return $this->resolveUncached($id, $lifetime, $alias, $scope);
    }

    /**
     * Retain legacy seed priority and class discovery while applying lifetime tracking.
     *
     * @internal 10.x fixture compatibility on ConfigurationContainer only.
     */
    public function getLegacy(string $id): mixed
    {
        $seed = null;
        if ($this->repository->findScopeSeed($id, $seed)) {
            $this->assertScopedResolutionAllowed($id);

            return $seed;
        }

        $definition = $this->repository->getFunctionDefinition($id);
        if ($definition instanceof AliasDefinition) {
            return $this->resolveAlias($id, $definition);
        }

        $resolved = $this->repository->getResolvedSingletonEntry($id);
        if ($resolved !== null || $this->repository->hasResolvedSingleton($id)) {
            return $resolved;
        }

        $lifetime = $this->repository->getDefinitionLifetime($id);
        $scope = 'root';
        if ($lifetime === LifetimeEnum::Scoped && $this->findResolvedScoped($id, $scope, $resolved)) {
            return $this->repository->fetchInstanceOrValue($resolved);
        }

        $missing = !$this->hasLegacy($id);
        if ($missing && !$this->repository->tryResolveMissing($id)) {
            throw new NotFoundException("No entry found for '$id'.");
        }
        if ($missing) {
            $lifetime = $this->repository->getDefinitionLifetime($id);
        }
        $scope = $lifetime === LifetimeEnum::Scoped ? $this->repository->getScope() : null;

        return match ($lifetime) {
            LifetimeEnum::Singleton => $this->resolveAndCache($id, true, null),
            LifetimeEnum::Transient => $this->resolveAndCache($id, false, null),
            LifetimeEnum::Scoped => $this->resolveAndCache($id, true, $scope),
        };
    }

    /**
     * Resolve a declared service and expose its recorded post-construction method result.
     *
     * @throws ContainerException|InvalidArgumentException|ReflectionException
     */
    public function getReturn(string $id): mixed
    {
        $resolved = $this->get($id);

        return $this->resolvedReturn($id, $resolved);
    }

    /**
     * Expose a legacy resolution's post-construction method result when one was recorded.
     *
     * @internal
     */
    public function getReturnLegacy(string $id): mixed
    {
        $resolved = $this->getLegacy($id);

        return $this->resolvedReturn($id, $resolved);
    }

    /**
     * Check declaration presence without constructing services or discovering arbitrary classes.
     */
    public function has(string $id): bool
    {
        return $this->repository->hasFunctionReference($id);
    }

    /**
     * Include legacy resources, cached entries, classes and environment-bound interfaces.
     *
     * @internal
     */
    public function hasLegacy(string $id): bool
    {
        return $this->repository->hasFunctionReference($id)
            || $this->repository->hasClosureResource($id)
            || $this->repository->hasResolved($id)
            || class_exists($id)
            || (interface_exists($id) && $this->repository->getEnvConcrete($id) !== null);
    }

    /**
     * Inject missing callable arguments while preserving caller-supplied argument overrides.
     *
     * @param array<int|string, mixed> $arguments
     */
    public function invoke(callable $callable, array $arguments = []): mixed
    {
        return ContainerAccess::resolver($this->container)->closureSettler($callable, $arguments);
    }

    /**
     * Construct a fresh class instance without returning its cached lifetime instance.
     *
     * @param array<int|string, mixed> $arguments
     */
    public function make(string $class, array $arguments = []): object
    {
        $fresh = ContainerAccess::resolver($this->container)->classSettler(
            $class,
            false,
            true,
            constructorParameters: $arguments,
        );

        return $fresh->instance;
    }

    /**
     * Resolve a definition recipe and unwrap its constructed instance for service callers.
     *
     * @throws ContainerException|ReflectionException
     */
    protected function resolveDefinition(string $id): mixed
    {
        return $this->repository->fetchInstanceOrValue(
            ContainerAccess::resolver($this->container)->resolveByDefinition($id),
        );
    }

    private function callCallable(callable $callable, string|bool|null $method): mixed
    {
        if (is_string($method) && $method !== '') {
            throw new ContainerException('A method cannot be supplied when invoking a callable.');
        }

        return ContainerAccess::resolver($this->container)->closureSettler($callable);
    }

    private function callClass(string $class, string|bool|null $method): mixed
    {
        if (!$this->has($class)) {
            $activated = $this->repository->tryResolveMissing($class);
            if (!$activated && !interface_exists($class)) {
                throw new NotFoundException("No entry found for '$class'.");
            }
        }
        if ($this->repository->hasFunctionReference($class)) {
            return $this->callDefinition($class, $method);
        }

        $targetMethod = $method === false ? false : (is_string($method) ? $method : null);
        $resolved = ContainerAccess::resolver($this->container)->classSettler($class, $targetMethod);

        return $resolved->methodInvoked ? $resolved->returned : $resolved->instance;
    }

    private function callClosureResource(string $alias, string|bool|null $method): mixed
    {
        if (is_string($method) && $method !== '') {
            throw new ContainerException('A method cannot be supplied when invoking a callable.');
        }

        $closureRes = $this->repository->getClosureResourceEntry($alias) ?? [];
        $on = $closureRes['on'] ?? null;
        if (!is_callable($on)) {
            throw new ContainerException("Closure resource '$alias' is not callable.");
        }

        return ContainerAccess::resolver($this->container)->closureSettler($on, $closureRes['params'] ?? []);
    }

    private function callDefinition(string $id, string|bool|null $method): mixed
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

    private function findResolvedScoped(string $id, string &$scope, mixed &$resolved): bool
    {
        $this->assertScopedResolutionAllowed($id);
        $scope = $this->repository->getScope();
        $resolved = $this->repository->getResolvedScopedEntry($scope, $id);

        return $resolved !== null || $this->repository->hasResolvedScoped($scope, $id);
    }

    private function popLifetime(): void
    {
        $owner = $this->resolutionOwner();
        array_pop($this->lifetimeStacks[$owner]);
        if ($this->lifetimeStacks[$owner] === []) {
            unset($this->lifetimeStacks[$owner]);
        }
        $this->singletonResolutionActive = $this->lifetimeStacks !== [];
    }

    private function pushLifetime(LifetimeEnum $lifetime): void
    {
        $owner = $this->resolutionOwner();
        $this->lifetimeStacks[$owner][] = $lifetime;
        $this->singletonResolutionActive = true;
    }

    private function resolutionOwner(): string
    {
        return ExecutionContext::id() ?? "\0intermix.root";
    }

    private function resolveAlias(string $id, AliasDefinition $definition): mixed
    {
        $seen = [$id => true];
        $target = $definition->target;

        while (true) {
            if (isset($seen[$target])) {
                throw new ContainerException("Circular alias dependency for '{$id}'.");
            }
            $seen[$target] = true;

            if ($this->repository->isTracingEnabled()) {
                $this->repository->tracer()->recordDependency($id, $target, 'alias');
            }

            $next = $this->repository->getFunctionDefinition($target);
            if (!$next instanceof AliasDefinition) {
                return $this->container->get($target);
            }

            $id = $target;
            $target = $next->target;
        }
    }

    private function resolveAndCache(string $id, bool $cacheable, ?string $scope): mixed
    {
        if ($scope === null && $cacheable) {
            return $this->resolveAndCacheSingleton(
                $id,
                $this->repository instanceof ConcurrentRepository ? $this->repository : null,
            );
        }
        if ($this->repository instanceof ConcurrentRepository
            && $scope !== null
            && $this->repository->requiresScopedConstructionGuard()
        ) {
            return $this->resolveAndCacheGuarded($id, $cacheable, $scope, $this->repository);
        }

        return $this->resolveAndCacheDirect($id, $cacheable, $scope);
    }

    private function resolveAndCacheDirect(string $id, bool $cacheable, ?string $scope): mixed
    {
        $this->repository->dispatchResolvingHooks($id);

        if ($this->repository->hasFunctionReference($id)) {
            $resolved = $this->resolveDefinition($id);
        } else {
            $resolution = ContainerAccess::resolver($this->container)->classSettler($id);
            $resolved = $this->repository->fetchInstanceOrValue($resolution);
            if ($cacheable) {
                $this->storeResolvedByLifetime($id, $resolution, $scope);
            }
            $this->repository->dispatchResolvedHooks($id, $resolved);

            return $resolved;
        }

        if ($cacheable) {
            $this->storeResolvedByLifetime($id, $resolved, $scope);
        }
        $this->repository->dispatchResolvedHooks($id, $resolved);

        return $resolved;
    }

    private function resolveAndCacheGuarded(
        string $id,
        bool $cacheable,
        string $scope,
        ConcurrentRepository $repository,
    ): mixed {
        $constructionOwner = $repository->beginScopedConstruction($scope, $id);

        try {
            return $this->resolveAndCacheDirect($id, $cacheable, $scope);
        } finally {
            if ($constructionOwner) {
                $repository->endScopedConstruction($scope, $id);
            }
        }
    }

    private function resolveAndCacheSingleton(string $id, ?ConcurrentRepository $repository): mixed
    {
        $constructionOwner = $repository?->beginSingletonConstruction($id) ?? false;
        $this->pushLifetime(LifetimeEnum::Singleton);

        try {
            return $this->resolveAndCacheDirect($id, true, null);
        } finally {
            $this->popLifetime();
            if ($constructionOwner) {
                $repository->endSingletonConstruction($id);
            }
        }
    }

    private function resolvedReturn(string $id, mixed $resolved): mixed
    {
        $lifetime = $this->repository->getDefinitionLifetime($id);
        $resource = $lifetime === LifetimeEnum::Scoped
            ? $this->repository->getResolvedScopedEntry($this->repository->getScope(), $id)
            : $this->repository->getResolvedEntry($id);

        return $resource instanceof ClassResolution && $resource->methodInvoked
            ? $resource->returned
            : $resolved;
    }

    private function resolveUncached(string $id, LifetimeEnum $lifetime, ?AliasDefinition $alias, string $scope): mixed
    {
        if ($lifetime === LifetimeEnum::Scoped && $scope === 'root') {
            throw new ContainerException("Scoped entry '$id' requires an active scope.");
        }
        if (!$this->has($id)) {
            throw new NotFoundException("No entry found for '$id'.");
        }

        if ($alias instanceof AliasDefinition) {
            return $this->resolveAlias($id, $alias);
        }
        if ($this->repository->isTracingEnabled()) {
            $this->repository->tracer()->push("return:$id", TraceLevelEnum::Verbose);
        }

        return match ($lifetime) {
            LifetimeEnum::Singleton => $this->resolveAndCache($id, true, null),
            LifetimeEnum::Transient => $this->resolveAndCache($id, false, null),
            LifetimeEnum::Scoped => $this->resolveAndCache($id, true, $scope),
        };
    }

    private function storeResolvedByLifetime(string $id, mixed $resolved, ?string $scope): void
    {
        if ($scope !== null) {
            $this->repository->setResolvedScoped($scope, $id, $resolved);

            return;
        }

        $this->repository->setResolved($id, $resolved);
    }
}
