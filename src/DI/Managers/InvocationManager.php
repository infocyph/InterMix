<?php

declare(strict_types=1);

namespace Infocyph\InterMix\DI\Managers;

use Closure;
use Infocyph\InterMix\DI\Container;
use Infocyph\InterMix\DI\Resolver\Repository;

/**
 * Handles get(), has(), getReturn(), call(), make() with optional lazy loading.
 */
class InvocationManager
{
    public function __construct(
        protected Repository $repository,
        protected Container $container
    ) {
    }

    /**
     * Return the "returned" value from a resolved resource if it exists.
     */
    public function getReturn(string $id): mixed
    {
        $resolved = $this->get($id);
        $resource = $this->repository->getResolved()[$id] ?? [];

        return $resource['returned'] ?? $resolved;
    }

    /**
     * Main resolution routine for ID-based retrieval.
     */
    public function get(string $id): mixed
    {
        // Check if already resolved
        $allResolved = $this->repository->getResolved();
        if (isset($allResolved[$id])) {
            // If we did "lazy", we might store a closure or placeholder
            $existing = $allResolved[$id];

            // If it's an array with e.g. ['lazyPlaceholder' => <callable>], do real resolution now
            if (isset($existing['lazyPlaceholder']) && is_callable($existing['lazyPlaceholder'])) {
                $this->repository->setResolved($id, $existing['lazyPlaceholder']());
            }

            $fresh = $this->repository->getResolved()[$id];

            return $fresh['instance'] ?? $fresh;
        }

        // If in functionReference => resolve definition
        if (array_key_exists($id, $this->repository->getFunctionReference())) {
            return $this->resolveDefinition($id);
        }

        // Otherwise, attempt call with $id as a class name
        $resolved = $this->call($id);

        $this->repository->setResolved($id, $resolved);

        return $resolved['instance'] ?? $resolved;
    }

    public function has(string $id): bool
    {
        return array_key_exists($id, $this->repository->getFunctionReference()) ||
            isset($this->repository->getResolved()[$id]);
    }

    /**
     * call() resolves a class/closure in multiple ways
     */
    public function call(string|Closure|callable $classOrClosure, string|bool|null $method = null): mixed
    {
        $resolverClass = $this->container->getRepositoryResolverClass();
        $resolver = new $resolverClass($this->repository);

        // 1) If string & in functionReference
        if (is_string($classOrClosure) &&
            array_key_exists($classOrClosure, $this->repository->getFunctionReference())) {
            return $resolver->resolveByDefinition($classOrClosure);
        }

        // 2) If a closure/callable
        if ($classOrClosure instanceof Closure || is_callable($classOrClosure)) {
            return $resolver->closureSettler($classOrClosure);
        }

        // 3) If closure alias
        $closureRes = $this->repository->getClosureResource();
        if (is_string($classOrClosure) && isset($closureRes[$classOrClosure])) {
            $on = $closureRes[$classOrClosure]['on'];
            $params = $closureRes[$classOrClosure]['params'];

            return $resolver->closureSettler($on, $params);
        }

        // 4) Otherwise assume class name
        return $resolver->classSettler($classOrClosure, $method);
    }

    /**
     * make(): always produce a fresh new instance ignoring any existing resolution.
     */
    public function make(string $class, string|bool $method = false): mixed
    {
        $resolverClass = $this->container->getRepositoryResolverClass();
        $resolver = new $resolverClass($this->repository);

        $fresh = $resolver->classSettler($class, $method, make: true);

        return $method ? $fresh['returned'] : $fresh['instance'];
    }

    /**
     * Resolve a definition & store in "resolved". If lazy loading is on, we might store a placeholder.
     */
    protected function resolveDefinition(string $id): mixed
    {
        $resolverClass = $this->container->getRepositoryResolverClass();
        $resolver = new $resolverClass($this->repository);

        // If lazyLoading is on and we consider $id non-critical, we can store a lazy placeholder.
        if ($this->repository->isLazyLoading()) {
            // Example approach: store a closure that does real resolution
            $lazy = [
                'lazyPlaceholder' => function () use ($resolver, $id) {
                    return $resolver->resolveByDefinition($id); // array with 'instance' etc.
                },
            ];
            $this->repository->setResolved($id, $lazy);

            return $lazy; // user won't get a real instance until "get($id)" is re-called
        }

        // Otherwise, do immediate resolution
        $value = $resolver->resolveByDefinition($id);
        $this->repository->setResolved($id, $value);

        return is_array($value) && isset($value['instance']) ? $value['instance'] : $value;
    }

    public function definitions(): DefinitionManager
    {
        return $this->container->definitions();
    }

    public function registration(): RegistrationManager
    {
        return $this->container->registration();
    }

    public function options(): OptionsManager
    {
        return $this->container->options();
    }

    public function end(): Container
    {
        return $this->container;
    }
}
