<?php

declare(strict_types=1);

namespace Infocyph\InterMix\DI\Managers;

use Infocyph\InterMix\DI\Container;
use Infocyph\InterMix\DI\Resolver\Repository;
use Infocyph\InterMix\Exceptions\ContainerException;
use Infocyph\InterMix\ServiceProvider\ServiceProviderInterface;
use Symfony\Contracts\Cache\CacheInterface;

/**
 * Handles adding/binding definitions & caching, plus optional service providers.
 */
class DefinitionManager
{
    public function __construct(
        protected Repository $repository,
        protected Container $container
    ) {
    }

    /**
     * Add multiple definitions at once.
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
     * Bind a single definition.
     */
    public function bind(string $id, mixed $definition): self
    {
        // Lock check inside repository->setFunctionReference()
        if ($id === $definition) {
            throw new ContainerException("Id and definition cannot be the same ($id)");
        }
        $this->repository->setFunctionReference($id, $definition);

        return $this;
    }

    /**
     * Register a "service provider" class that can bulk-register services.
     */
    public function registerProvider(ServiceProviderInterface $provider): self
    {
        // The provider might call ->addDefinitions(), ->bind(), ->registerClass(), etc.
        $provider->register($this->container);

        return $this;
    }

    /**
     * Enable caching for definitions.
     */
    public function enableDefinitionCache(CacheInterface $cache): self
    {
        $this->repository->setCacheAdapter($cache);

        return $this;
    }

    /**
     * Cache all definitions
     */
    public function cacheAllDefinitions(bool $forceClearFirst = false): self
    {
        if (empty($this->repository->getFunctionReference())) {
            throw new ContainerException('No definitions added.');
        }
        $cacheAdapter = $this->repository->getCacheAdapter();
        if (! $cacheAdapter) {
            throw new ContainerException('No cache adapter set.');
        }
        if ($forceClearFirst) {
            // Clear container-specific keys
            $cacheAdapter->clear($this->repository->makeCacheKey(''));
        }

        // Use the container’s set resolver to pre-resolve
        $resolverClass = $this->container->getRepositoryResolverClass();
        $resolver = new $resolverClass($this->repository);

        foreach ($this->repository->getFunctionReference() as $id => $_def) {
            // This triggers definition resolution + caching
            $resolver->resolveByDefinition($id);
        }

        return $this;
    }

    /**
     * Jump to RegistrationManager
     */
    public function registration(): RegistrationManager
    {
        return $this->container->registration();
    }

    /**
     * Jump to OptionsManager
     */
    public function options(): OptionsManager
    {
        return $this->container->options();
    }

    /**
     * Jump to InvocationManager
     */
    public function invocation(): InvocationManager
    {
        return $this->container->invocation();
    }

    /**
     * End chain, return container
     */
    public function end(): Container
    {
        return $this->container;
    }
}
