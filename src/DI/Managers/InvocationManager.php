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
    /**
     * Constructs an InvocationManager.
     *
     * @param Repository $repository The internal repository of definitions, resolved instances, etc.
     * @param Container $container The container instance to which this manager is bound.
     */
    public function __construct(
        protected Repository $repository,
        protected Container $container
    ) {
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
     */
    public function getReturn(string $id): mixed
    {
        $resolved = $this->get($id);
        $resource = $this->repository->getResolved()[$id] ?? [];

        return $resource['returned'] ?? $resolved;
    }


    /**
     * Resolve a definition ID and return the resolved instance.
     *
     * If the ID is already resolved, the cached instance is returned.
     * If the ID is a function reference, the definition is resolved and the result is returned.
     * Otherwise, the method attempts to call the ID as a class name and returns the result.
     *
     * If lazy loading is enabled and the resolved instance is an array with a 'lazyPlaceholder' key,
     * the placeholder is resolved and the result is stored in the cache.
     *
     * @param string $id The ID of the definition to resolve and return.
     *
     * @return mixed The resolved instance, or the result of the resolved instance if it is a callable.
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

    /**
     * Checks if a definition ID exists in the repository.
     *
     * This method determines whether a given definition ID is present
     * either in the function references or among the resolved instances
     * in the repository.
     *
     * @param string $id The ID of the definition to check.
     * @return bool True if the definition ID exists, false otherwise.
     */
    public function has(string $id): bool
    {
        return array_key_exists($id, $this->repository->getFunctionReference()) ||
            isset($this->repository->getResolved()[$id]);
    }


    /**
     * Invokes a given class or closure with optional method name.
     *
     * Depending on the type of the given $classOrClosure, the method
     * does the following:
     *
     * 1. If $classOrClosure is a string and exists in the function references,
     *    the definition is resolved using the RepositoryResolver.
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
     */
    public function make(string $class, string|bool $method = false): mixed
    {
        $resolverClass = $this->container->getRepositoryResolverClass();
        $resolver = new $resolverClass($this->repository);

        $fresh = $resolver->classSettler($class, $method, make: true);

        return $method ? $fresh['returned'] : $fresh['instance'];
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
     * Returns the registration manager for the container.
     *
     * @return RegistrationManager The registration manager.
     */
    public function registration(): RegistrationManager
    {
        return $this->container->registration();
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
     * Ends the invocation manager and returns the container instance.
     *
     * This method is typically used when you want to exit the invocation manager
     * and return to the container instance.
     *
     * @return Container The container instance.
     */
    public function end(): Container
    {
        return $this->container;
    }
}
