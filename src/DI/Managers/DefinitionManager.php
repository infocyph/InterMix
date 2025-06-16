<?php

declare(strict_types=1);

namespace Infocyph\InterMix\DI\Managers;

use Infocyph\InterMix\Cache\Cache;
use Infocyph\InterMix\DI\Container;
use Infocyph\InterMix\DI\Reflection\Lifetime;
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
     * Registers a definition with the container.
     *
     * Registers a definition with the container, which can then be retrieved
     * using the {@see get()} method. The definition can be any type of value,
     * including another definition.
     *
     * @param string $id The identifier of the definition.
     * @param mixed $definition The definition itself.
     * @param Lifetime $lifetime The lifetime of the definition.
     * @param array $tags An array of tags to associate with the definition.
     *
     * @return $this
     * @throws ContainerException if the container is locked or if the id is the same as the definition.
     */
    public function bind(
        string $id,
        mixed $definition,
        Lifetime $lifetime = Lifetime::Singleton,
        array $tags = []
    ): self {
        if ($id === $definition) {
            throw new ContainerException("Id and definition cannot be the same ($id)");
        }
        $this->repository->setFunctionReference($id, $definition);
        $this->repository->setDefinitionMeta($id, [
            'lifetime' => $lifetime,
            'tags'     => $tags,
        ]);
        return $this;
    }


    /**
     * Enable definition caching.
     *
     * This method takes a {@see CacheItemPoolInterface} and enables caching of
     * definitions. It will throw a {@see ContainerException} if the container
     * is locked.
     *
     * @param string|null $namespace The namespace to use for the cache.
     * @return $this
     * @throws ContainerException
     */
    public function enableDefinitionCache(?string $namespace = null): self
    {
        $this->repository->setCacheAdapter(Cache::file($namespace ?? $this->repository->getAlias(), 'intermix_dfn'));
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
            $cacheAdapter->clear();
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
