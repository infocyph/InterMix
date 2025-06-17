<?php

declare(strict_types=1);

namespace Infocyph\InterMix\DI\Managers;

use Closure;
use Infocyph\InterMix\DI\Attribute\DeferredInitializer;
use Infocyph\InterMix\DI\Container;
use Infocyph\InterMix\DI\Support\Lifetime;
use Infocyph\InterMix\DI\Support\TraceLevel;
use Infocyph\InterMix\DI\Resolver\Repository;
use Infocyph\InterMix\Exceptions\ContainerException;
use Psr\Cache\InvalidArgumentException;
use ReflectionException;

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
     * @throws ContainerException
     * @throws InvalidArgumentException
     * @throws ReflectionException
     */
    public function getReturn(string $id): mixed
    {
        $resolved = $this->get($id);
        $resource = $this->repository->getResolved()[$id] ?? [];

        return array_key_exists('returned', $resource) ? $resource['returned'] : $resolved;
    }

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
        $this->repository->tracer()->push("return:$id", TraceLevel::Verbose);
        // Determine lifetime & scope-key
        $meta      = $this->repository->getDefinitionMeta($id);
        $lifetime  = $meta['lifetime'] ?? Lifetime::Singleton;
        $scopeKey  = $lifetime === Lifetime::Scoped
            ? $id.'@'.$this->repository->getScope()
            : $id;
        $cacheable = $lifetime !== Lifetime::Transient;

        // Fast return from cache (singleton / scoped)
        if ($cacheable && isset($this->repository->getResolved()[$scopeKey])) {
            $resolved = $this->repository->getResolved()[$scopeKey];

            if ($resolved instanceof DeferredInitializer) {
                $resolved = $resolved();
                $this->repository->setResolved($scopeKey, $resolved);
            }
            $this->repository->tracer()->pop();
            return $this->repository->fetchInstanceOrValue($resolved);
        }

        // Resolve: definition map → class/closure fallback
        if (array_key_exists($id, $this->repository->getFunctionReference())) {
            $resolved = $this->resolveDefinition($id);
            $resolved = $resolved instanceof DeferredInitializer ? $resolved() : $resolved;

            if ($cacheable) {
                $this->repository->setResolved($scopeKey, $resolved);
            }
            $this->repository->tracer()->pop();
            return $resolved;
        }

        // Fallback: treat $id as class name / closure alias
        $resolved = $this->call($id);

        if ($cacheable) {
            $this->repository->setResolved($scopeKey, $resolved);
        }
        $this->repository->tracer()->pop();
        return $this->repository->fetchInstanceOrValue($resolved);
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
     * @throws ContainerException
     * @throws ReflectionException
     * @throws InvalidArgumentException
     */
    public function call(string|Closure|callable $classOrClosure, string|bool|null $method = null): mixed
    {
        $resolver = $this->container->getCurrentResolver();

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
     * @throws ContainerException
     * @throws ReflectionException
     */
    public function make(string $class, string|bool $method = false): mixed
    {
        $resolver = $this->container->getCurrentResolver();

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
     * @throws ContainerException|InvalidArgumentException
     * @throws ReflectionException
     */
    protected function resolveDefinition(string $id): mixed
    {
        $resolver = $this->container->getCurrentResolver();
        $definition = $this->repository->getFunctionReference()[$id];

        if ($this->repository->isLazyLoading() && ! ($definition instanceof Closure)) {
            $lazy = new DeferredInitializer(fn () => $resolver->resolveByDefinition($id), $this->container);
            $this->repository->setResolved($id, $lazy);
            return $lazy;
        }

        $value = $resolver->resolveByDefinition($id);
        $this->repository->setResolved($id, $value);

        return $this->repository->fetchInstanceOrValue($value);
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
