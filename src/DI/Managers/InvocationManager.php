<?php

declare(strict_types=1);

namespace Infocyph\InterMix\DI\Managers;

use Closure;
use Exception;
use Infocyph\InterMix\DI\Container;
use Infocyph\InterMix\DI\Resolver\Repository;
use Infocyph\InterMix\Exceptions\ContainerException;

/**
 * Handles "invocation"-related methods:
 *   - get(), has(), getReturn()
 *   - call(), make()
 */
class InvocationManager
{
    public function __construct(
        protected Repository $repository,
        protected Container  $container
    ) {
        //
    }

    /**
     * Return the "returned" value from the resolved resource if it exists, otherwise the instance.
     */
    public function getReturn(string $id): mixed
    {
        // Just call get($id) for the instance
        $resolved = $this->get($id);

        // Then see if there's a "returned" value in $this->repository->resolved[$id]
        $resource = $this->repository->getResolved()[$id] ?? [];
        return $resource['returned'] ?? $resolved;
    }

    /**
     * Main resolution routine to get an instance by ID.
     */
    public function get(string $id): mixed
    {
        // Already in resolved?
        $resolvedAll = $this->repository->getResolved();
        if (isset($resolvedAll[$id])) {
            $existing = $resolvedAll[$id];
            return $existing['instance'] ?? $existing;
        }

        // If in functionReference => we can resolve by definition
        if ($this->repository->hasFunctionReference($id)) {
            return $this->resolveDefinition($id);
        }

        // Otherwise, try call() with $id as a class/closure name
        $value = $this->call($id);
        // If returned array includes 'instance', return that, else the array itself
        return $value['instance'] ?? $value;
    }

    /**
     * Whether the container has a definition or a previously-resolved instance.
     * If neither, tries to see if get($id) can succeed without error.
     */
    public function has(string $id): bool
    {
        // 1) Is in function reference?
        if ($this->repository->hasFunctionReference($id)) {
            return true;
        }
        // 2) Is in resolved array?
        if (isset($this->repository->getResolved()[$id])) {
            return true;
        }
        // 3) Attempt final resolution
        try {
            $this->get($id);
            return true;
        } catch (Exception) {
            return false;
        }
    }

    /**
     * call() resolves a class or closure in multiple ways:
     *   - If a string ID in functionReference => resolveByDefinition
     *   - If a closure/callable => pass to the chosen resolver's closureSettler
     *   - If a closure alias => also pass
     *   - If a string class name => pass to resolver->classSettler
     */
    public function call(
        string|Closure|callable $classOrClosure,
        string|bool|null $method = null
    ): mixed {
        // Determine which invoker to use (InjectedCall or GenericCall)
        $resolverClass = $this->container->getRepositoryResolverClass();
        $resolver      = new $resolverClass($this->repository);

        // 1) If string and is a known functionReference ID => resolve it
        if (is_string($classOrClosure) && $this->repository->hasFunctionReference($classOrClosure)) {
            return $resolver->resolveByDefinition($classOrClosure);
        }

        // 2) If closure or generic callable => closureSettler
        if ($classOrClosure instanceof Closure || is_callable($classOrClosure)) {
            return $resolver->closureSettler($classOrClosure);
        }

        // 3) If it’s a closure alias in closureResource
        $closureRes = $this->repository->getClosureResource();
        if (is_string($classOrClosure) && isset($closureRes[$classOrClosure]['on'])) {
            $on     = $closureRes[$classOrClosure]['on'];
            $params = $closureRes[$classOrClosure]['params'];
            return $resolver->closureSettler($on, $params);
        }

        // 4) Otherwise assume it’s a class name
        if (is_string($classOrClosure)) {
            return $resolver->classSettler($classOrClosure, $method);
        }

        throw new ContainerException('Invalid class/closure format in call().');
    }

    /**
     * Create a new instance ignoring any existing singletons/resolved.
     */
    public function make(string $class, string|bool $method = false): mixed
    {
        $resolverClass = $this->container->getRepositoryResolverClass();
        $resolver      = new $resolverClass($this->repository);

        $resolved = $resolver->classSettler($class, $method, make: true);
        return $method ? $resolved['returned'] : $resolved['instance'];
    }

    /**
     * Helper: resolves a definition for $id and store in repository->resolved[$id].
     */
    protected function resolveDefinition(string $id): mixed
    {
        $resolverClass = $this->container->getRepositoryResolverClass();
        $resolver      = new $resolverClass($this->repository);

        $instance = $resolver->resolveByDefinition($id);

        // Store it in 'resolved'
        $allResolved = $this->repository->getResolved();
        $allResolved[$id] = $instance;
        $this->repository->setResolved($id, $instance);

        return $instance['instance'] ?? $instance;
    }

    /**
     * Optional: chain back to the parent Container if desired.
     */
    public function end(): Container
    {
        return $this->container;
    }
}
