<?php

declare(strict_types=1);

namespace Infocyph\InterMix\DI\Managers;

use Infocyph\InterMix\DI\Container;
use Infocyph\InterMix\DI\Resolver\Repository;
use Infocyph\InterMix\Exceptions\ContainerException;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Cache\InvalidArgumentException;

class DefinitionManager
{
    /**
     * Initialize the definition manager.
     *
     * @param Repository $repository The internal repository holding all definitions.
     * @param Container $container The container instance this manager is bound to.
     */
    public function __construct(
        protected Repository $repository,
        protected Container $container,
    ) {
    }


    /**
     * Adds multiple definitions to the container.
     *
     * This method takes an associative array with definition names as keys and
     * the definitions themselves as values. It then internally calls the
     * {@see bind()} method for each definition.
     *
     * @param array<string, mixed> $definitions The array of definitions.
     *
     * @return $this
     * @throws ContainerException
     */
    public function addDefinitions(array $definitions): self
    {
        // The repository internally checks for lock
        foreach ($definitions as $id => $definition) {
            $this->bind($id, $definition);
        }

        return $this;
    }


    /**
     * Registers a single definition with the container.
     *
     * This method takes a definition name (id) and a definition value and
     * stores it in the internal repository. It will throw a
     * {@see ContainerException} if the id and definition are the same, as
     * that would be ambiguous.
     *
     * @param string $id The id of the definition to register.
     * @param mixed $definition The definition value to register.
     *
     * @return $this
     * @throws ContainerException
     */
    public function bind(string $id, mixed $definition): self
    {
        if ($id === $definition) {
            throw new ContainerException("Id and definition cannot be the same ($id)");
        }
        $this->repository->setFunctionReference($id, $definition);

        return $this;
    }


    /**
     * Enable definition caching.
     *
     * This method takes a {@see CacheItemPoolInterface} and enables caching of
     * definitions. It will throw a {@see ContainerException} if the container
     * is locked.
     *
     * @param CacheItemPoolInterface $cache The cache adapter to use for caching.
     *
     * @return $this
     * @throws ContainerException
     */
    public function enableDefinitionCache(CacheItemPoolInterface $cache): self
    {
        $this->repository->setCacheAdapter($cache);

        return $this;
    }


    /**
     * Pre-cache all definitions.
     *
     * This method takes a boolean to force-clear the cache before caching
     * definitions. It will throw a {@see ContainerException} if no definitions
     * are added or if no cache adapter is set.
     *
     * @param bool $forceClearFirst Whether to clear the cache before caching all definitions.
     *
     * @return $this
     * @throws ContainerException|InvalidArgumentException
     */
    public function cacheAllDefinitions(bool $forceClearFirst = false): self
    {
        if (empty($this->repository->getFunctionReference())) {
            throw new ContainerException('No definitions added.');
        }
        $cacheAdapter = $this->repository->getCacheAdapter();
        if (!$cacheAdapter) {
            throw new ContainerException('No cache adapter set.');
        }
        if ($forceClearFirst) {
            // Clear container-specific keys
            $cacheAdapter->clear($this->repository->makeCacheKey(''));
        }

        // Use the container’s set resolver to pre-resolve
        $resolver = $this->container->getCurrentResolver();

        foreach ($this->repository->getFunctionReference() as $id => $_def) {
            // This triggers definition resolution + caching
            $resolver->resolveByDefinition($id);
        }

        return $this;
    }


    /**
     * Jump to RegistrationManager
     *
     * @return RegistrationManager
     */
    public function registration(): RegistrationManager
    {
        return $this->container->registration();
    }


    /**
     * Jump to OptionsManager
     *
     * @return OptionsManager
     */
    public function options(): OptionsManager
    {
        return $this->container->options();
    }

    /**
     * Jump to InvocationManager
     *
     * @return InvocationManager
     */
    public function invocation(): InvocationManager
    {
        return $this->container->invocation();
    }

    /**
     * Ends the current scope and returns the Container instance.
     *
     * When called, this method will return the Container instance and
     * remove the current scope from the stack, effectively ending the
     * current scope.
     *
     * @return Container The Container instance.
     */
    public function end(): Container
    {
        return $this->container;
    }
}
