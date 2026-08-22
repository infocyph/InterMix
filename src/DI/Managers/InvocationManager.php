<?php

declare(strict_types=1);

namespace Infocyph\InterMix\DI\Managers;

use ArrayAccess;
use Closure;
use Infocyph\InterMix\DI\Container;
use Infocyph\InterMix\DI\Internal\ClassResolution;
use Infocyph\InterMix\DI\Internal\DeferredInitializer;
use Infocyph\InterMix\DI\Resolver\Repository;
use Infocyph\InterMix\DI\Support\LifetimeEnum;
use Infocyph\InterMix\DI\Support\TraceLevelEnum;
use Infocyph\InterMix\Exceptions\ContainerException;
use Infocyph\InterMix\Exceptions\NotFoundException;
use Psr\Cache\InvalidArgumentException;
use ReflectionException;

/**
 * Handles get(), has(), getReturn(), call(), make() with optional lazy loading.
 *
 * @implements ArrayAccess<string, mixed>
 */
class InvocationManager implements ArrayAccess
{
    use ManagerProxy;

    /**
     * Constructs an InvocationManager.
     *
     * @param Repository $repository The internal repository of definitions, resolved instances, etc.
     * @param Container $container The container instance to which this manager is bound.
     */
    public function __construct(
        protected Repository $repository,
        protected Container $container,
    ) {}

    /**
     * Invokes a given class or closure with optional method name.
     *
     * Depending on the type of the given $classOrClosure, the method
     * does the following:
     *
     * 1. If $classOrClosure is a string and exists in the function references,
     *    the definition is resolved through get() so lifetime/scope caches and
     *    lifecycle hooks are preserved.
     *
     * 2. If $classOrClosure is a closure or callable, the closure is invoked
     *    with resolved parameters using the RepositoryResolver.
     *
     * 3. If $classOrClosure is a string and exists in the closure resources,
     *    the closure is invoked with the stored parameters using the
     *    RepositoryResolver.
     *
     * 4. If none of the above conditions are met, the method assumes $classOrClosure
     *    is a class name and attempts to resolve it using the RepositoryResolver.
     *
     * @param string|Closure|callable $classOrClosure The class or closure to invoke.
     * @param string|bool|null $method The optional method name to call.
     * @return mixed The result of invoking the class or closure.
     * @throws ContainerException
     * @throws ReflectionException
     * @throws InvalidArgumentException
     */
    public function call(string|Closure|callable $classOrClosure, string|bool|null $method = null): mixed
    {
        if (is_string($classOrClosure) && $this->repository->hasFunctionReference($classOrClosure)) {
            return $this->callDefinition($classOrClosure, $method);
        }

        $resolver = $this->container->getCurrentResolver();
        if ($classOrClosure instanceof Closure || is_callable($classOrClosure)) {
            return $this->callCallable($classOrClosure, $method);
        }

        if ($this->repository->hasClosureResource($classOrClosure)) {
            return $this->callClosureResource($classOrClosure, $method);
        }

        $targetMethod = $method === false ? false : (\is_string($method) ? $method : null);

        $resolved = $resolver->classSettler($classOrClosure, $targetMethod);

        return $resolved->methodInvoked ? $resolved->returned : $resolved->instance;
    }

    /**
     * Returns the definition manager for the container.
     *
     * @return DefinitionManager The definition manager.
     */
    public function definitions(): DefinitionManager
    {
        return $this->container->definitions();
    }

    /**
     * @internal
     */
    /**
     * Retrieves a value associated with a given ID from the container.
     *
     * The method first checks if the value is already resolved and cached based on
     * its lifetime and scope. If cached, it returns the cached value immediately.
     * Otherwise, it attempts to resolve the value using the definition map or by
     * treating the ID as a class name or closure alias. The resolved value is then
     * cached if it is cacheable.
     *
     * @param string $id The ID of the value to retrieve.
     *
     * @return mixed The resolved value or the cached value if available.
     * @throws ContainerException|InvalidArgumentException|ReflectionException If the value cannot be resolved.
     */
    public function get(string $id): mixed
    {
        $seed = null;
        if ($this->repository->findScopeSeed($id, $seed)) {
            return $seed;
        }

        if (!$this->has($id)) {
            throw new NotFoundException("No entry found for '$id'.");
        }

        $resolved = $this->repository->getResolvedSingletonEntry($id);
        if ($resolved !== null || $this->repository->hasResolvedSingleton($id)) {
            if ($resolved instanceof DeferredInitializer) {
                $resolved = $resolved();
                $this->repository->setResolved($id, $resolved);
            }

            return $resolved;
        }

        if ($this->repository->isTracingEnabled()) {
            $this->repository->tracer()->push("return:$id", TraceLevelEnum::Verbose);
        }

        $lifetime = $this->repository->getDefinitionLifetime($id);
        if ($lifetime === LifetimeEnum::Singleton) {
            return $this->resolveAndCache($id, $id, true, null);
        }

        if ($lifetime === LifetimeEnum::Transient) {
            return $this->resolveAndCache($id, $id, false, null);
        }

        $scope = $this->repository->getScope();

        return $this->resolveScoped($id, $scope);
    }

    /**
     * Resolves a definition ID and returns the result of the resolved instance.
     *
     * If the resolved instance is a closure, it is called with no arguments and
     * the result is returned. Otherwise, the resolved instance itself is returned.
     *
     * @param string $id The ID of the definition to resolve and return.
     *
     * @return mixed The result of the resolved instance, or the resolved instance itself.
     * @throws ContainerException
     * @throws InvalidArgumentException
     * @throws ReflectionException
     */
    public function getReturn(string $id): mixed
    {
        $resolved = $this->get($id);
        $lifetime = $this->repository->getDefinitionLifetime($id);
        $resource = $lifetime === LifetimeEnum::Scoped
            ? $this->repository->getResolvedScopedEntry($this->repository->getScope(), $id)
            : $this->repository->getResolvedEntry($id);

        return $resource instanceof ClassResolution && $resource->methodInvoked
            ? $resource->returned
            : $resolved;
    }

    /**
     * Determine whether an ID is explicitly registered, already cached, or autowireable.
     *
     * Environment-bound interfaces are also resolvable when their active
     * concrete implementation exists. This check does not resolve the entry.
     */
    public function has(string $id): bool
    {
        return $this->repository->hasFunctionReference($id)
            || $this->repository->hasClosureResource($id)
            || $this->repository->hasResolved($id)
            || class_exists($id)
            || (interface_exists($id) && $this->repository->getEnvConcrete($id) !== null);
    }

    /**
     * Creates a new instance of the given class with dependency injection,
     * without caching the result.
     *
     * This method is useful for creating objects that are not singletons,
     * but should still have their dependencies injected.
     *
     * If a method name is provided, it will be called on the newly created
     * instance and the return value will be returned.
     *
     * @param string $class The class name to create a new instance of.
     * @param string|bool $method The method to call on the instance, or false to not call a method.
     * @return mixed The newly created instance, or the result of the called method.
     * @throws ContainerException
     * @throws ReflectionException
     */
    public function make(string $class, string|bool $method = false): mixed
    {
        $resolver = $this->container->getCurrentResolver();
        $targetMethod = $method === false ? false : (\is_string($method) ? $method : null);
        $fresh = $resolver->classSettler($class, $targetMethod, true);

        return $fresh->methodInvoked ? $fresh->returned : $fresh->instance;
    }

    /**
     * Returns the options manager for the container.
     *
     * @return OptionsManager The options manager.
     */
    public function options(): OptionsManager
    {
        return $this->container->options();
    }

    /**
     * Returns the registration manager for the container.
     *
     * @return RegistrationManager The registration manager.
     */
    public function registration(): RegistrationManager
    {
        return $this->container->registration();
    }

    /**
     * Resolves a definition by its ID and returns the resolved instance.
     *
     * This method attempts to resolve the definition associated with the given
     * ID. If lazy loading is enabled, a lazy placeholder is stored, delaying
     * the actual resolution until the ID is accessed again. Otherwise, the
     * definition is resolved immediately.
     *
     * @param string $id The ID of the definition to resolve.
     *
     * @return mixed The resolved instance, or a lazy placeholder if lazy loading is enabled.
     * @throws ContainerException|InvalidArgumentException
     * @throws ReflectionException
     */
    protected function resolveDefinition(string $id): mixed
    {
        $resolver = $this->container->getCurrentResolver();

        $value = $resolver->resolveByDefinition($id);

        return $this->repository->fetchInstanceOrValue($value);
    }

    private function callCallable(callable $callable, string|bool|null $method): mixed
    {
        if (is_string($method) && $method !== '') {
            throw new ContainerException('A method cannot be supplied when invoking a callable.');
        }

        return $this->container->getCurrentResolver()->closureSettler($callable);
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
        $params = $closureRes['params'] ?? [];

        return $this->container->getCurrentResolver()->closureSettler($on, $params);
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

    private function resolveAndCache(string $id, string $scopeKey, bool $cacheable, ?string $scope): mixed
    {
        $this->repository->dispatchResolvingHooks($id);

        if ($this->repository->hasFunctionReference($id)) {
            $resolved = $this->resolveDefinition($id);
            $resolved = $resolved instanceof DeferredInitializer ? $resolved() : $resolved;

            if ($cacheable) {
                $this->storeResolvedByLifetime($scopeKey, $resolved, $scope);
            }

            $this->repository->dispatchResolvedHooks($id, $resolved);

            return $resolved;
        }

        $resolver = $this->container->getCurrentResolver();
        $resolved = $resolver->classSettler($id);
        if ($cacheable) {
            $this->storeResolvedByLifetime($scopeKey, $resolved, $scope);
        }

        $this->repository->dispatchResolvedHooks($id, $resolved);

        return $this->repository->fetchInstanceOrValue($resolved);
    }

    private function resolveScoped(string $id, string $scope): mixed
    {
        $cached = $this->repository->getResolvedScopedEntry($scope, $id);
        if ($cached === null && !$this->repository->hasResolvedScoped($scope, $id)) {
            return $this->resolveAndCache($id, $id, true, $scope);
        }

        if ($cached instanceof DeferredInitializer) {
            $cached = $cached();
            $this->storeResolvedByLifetime($id, $cached, $scope);
        }

        return $this->repository->fetchInstanceOrValue($cached);
    }

    private function storeResolvedByLifetime(string $scopeKey, mixed $resolved, ?string $scope): void
    {
        if ($scope !== null) {
            $this->repository->setResolvedScoped($scope, $scopeKey, $resolved);

            return;
        }

        $this->repository->setResolved($scopeKey, $resolved);
    }
}
