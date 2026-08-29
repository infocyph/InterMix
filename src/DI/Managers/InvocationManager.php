<?php

declare(strict_types=1);

namespace Infocyph\InterMix\DI\Managers;

use ArrayAccess;
use Closure;
use Infocyph\InterMix\DI\Container;
use Infocyph\InterMix\DI\Internal\ClassResolution;
use Infocyph\InterMix\DI\Resolver\Repository;
use Infocyph\InterMix\DI\Support\LifetimeEnum;
use Infocyph\InterMix\DI\Support\TraceLevelEnum;
use Infocyph\InterMix\Exceptions\ContainerException;
use Infocyph\InterMix\Exceptions\NotFoundException;
use Psr\Cache\InvalidArgumentException;
use ReflectionException;

/**
 * Handles get(), has(), getReturn(), call(), make().
 *
 * @implements ArrayAccess<string, mixed>
 */
class InvocationManager implements ArrayAccess
{
    use ManagerProxy;

    public function __construct(
        protected Repository $repository,
        protected Container $container,
    ) {}

    /**
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

        if (!$this->has($classOrClosure) && !$this->repository->tryResolveMissing($classOrClosure)) {
            throw new NotFoundException("No entry found for '$classOrClosure'.");
        }
        if ($this->repository->hasFunctionReference($classOrClosure)) {
            return $this->callDefinition($classOrClosure, $method);
        }

        $targetMethod = $method === false ? false : (is_string($method) ? $method : null);
        $resolved = $this->container->getCurrentResolver()->classSettler($classOrClosure, $targetMethod);

        return $resolved->methodInvoked ? $resolved->returned : $resolved->instance;
    }

    public function definitions(): DefinitionManager
    {
        return $this->container->definitions();
    }

    /**
     * Cached singleton and scoped entries are returned before any broad
     * resolvability checks. Mutations already invalidate these indexes, so a hot
     * lookup does not need to prove the service exists again.
     *
     * @throws ContainerException|InvalidArgumentException|ReflectionException
     */
    public function get(string $id): mixed
    {
        $seed = null;
        if ($this->repository->findScopeSeed($id, $seed)) {
            return $seed;
        }

        $resolved = $this->repository->getResolvedSingletonEntry($id);
        if ($resolved !== null || $this->repository->hasResolvedSingleton($id)) {
            return $resolved;
        }

        $lifetime = $this->repository->getDefinitionLifetime($id);
        $scope = null;
        if ($lifetime === LifetimeEnum::Scoped) {
            $scope = $this->repository->getScope();
            $resolved = $this->repository->getResolvedScopedEntry($scope, $id);
            if ($resolved !== null || $this->repository->hasResolvedScoped($scope, $id)) {
                return $this->repository->fetchInstanceOrValue($resolved);
            }
        }

        if (!$this->has($id) && !$this->repository->tryResolveMissing($id)) {
            throw new NotFoundException("No entry found for '$id'.");
        }

        if ($this->repository->isTracingEnabled()) {
            $this->repository->tracer()->push("return:$id", TraceLevelEnum::Verbose);
        }

        return match ($lifetime) {
            LifetimeEnum::Singleton => $this->resolveAndCache($id, true, null),
            LifetimeEnum::Transient => $this->resolveAndCache($id, false, null),
            LifetimeEnum::Scoped => $this->resolveAndCache($id, true, $scope ?? $this->repository->getScope()),
        };
    }

    /**
     * @throws ContainerException|InvalidArgumentException|ReflectionException
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

    public function has(string $id): bool
    {
        return $this->repository->hasFunctionReference($id)
            || $this->repository->hasClosureResource($id)
            || $this->repository->hasResolved($id)
            || class_exists($id)
            || (interface_exists($id) && $this->repository->getEnvConcrete($id) !== null);
    }

    /**
     * @throws ContainerException|ReflectionException
     */
    public function make(string $class, string|bool $method = false): mixed
    {
        $activated = false;
        if (!$this->has($class)) {
            $activated = $this->repository->tryResolveMissing($class);
        }
        if ($activated && $this->repository->hasFunctionReference($class)) {
            return $this->callDefinition($class, $method);
        }

        $targetMethod = $method === false ? false : (is_string($method) ? $method : null);
        $fresh = $this->container->getCurrentResolver()->classSettler($class, $targetMethod, true);

        return $fresh->methodInvoked ? $fresh->returned : $fresh->instance;
    }

    public function options(): OptionsManager
    {
        return $this->container->options();
    }

    public function registration(): RegistrationManager
    {
        return $this->container->registration();
    }

    /**
     * @throws ContainerException|InvalidArgumentException|ReflectionException
     */
    protected function resolveDefinition(string $id): mixed
    {
        return $this->repository->fetchInstanceOrValue(
            $this->container->getCurrentResolver()->resolveByDefinition($id),
        );
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

        return $this->container->getCurrentResolver()->closureSettler($on, $closureRes['params'] ?? []);
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

    private function resolveAndCache(string $id, bool $cacheable, ?string $scope): mixed
    {
        $this->repository->dispatchResolvingHooks($id);

        if ($this->repository->hasFunctionReference($id)) {
            $resolved = $this->resolveDefinition($id);
        } else {
            $resolution = $this->container->getCurrentResolver()->classSettler($id);
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

    private function storeResolvedByLifetime(string $id, mixed $resolved, ?string $scope): void
    {
        if ($scope !== null) {
            $this->repository->setResolvedScoped($scope, $id, $resolved);

            return;
        }

        $this->repository->setResolved($id, $resolved);
    }
}
