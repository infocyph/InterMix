<?php

declare(strict_types=1);

namespace Infocyph\InterMix\DI\Managers;

use Symfony\Contracts\Cache\CacheInterface;
use Infocyph\InterMix\DI\Container;
use Infocyph\InterMix\DI\Resolver\Repository;
use Infocyph\InterMix\Exceptions\ContainerException;

/**
 * Handles adding/binding definitions and definition caching.
 */
class DefinitionManager
{
    public function __construct(
        protected Repository $repository,
        protected Container  $container
    ) {
    }

    /**
     * Add multiple definitions at once.
     */
    public function addDefinitions(array $definitions): self
    {
        $this->repository->checkIfLocked();
        foreach ($definitions as $id => $definition) {
            $this->bind($id, $definition);
        }
        return $this;
    }

    /**
     * Bind a single definition to an ID.
     */
    public function bind(string $id, mixed $definition): self
    {
        $this->repository->checkIfLocked();

        // "Id and definition cannot be the same" check (carried from your original code)
        if ($id === $definition) {
            throw new ContainerException("Id and definition cannot be the same ($id)");
        }

        // Use a repository setter to store in functionReference
        $this->repository->setFunctionReference($id, $definition);

        return $this;
    }

    /**
     * Enable caching for definitions.
     */
    public function enableDefinitionCache(CacheInterface $cache): self
    {
        $this->repository->checkIfLocked();
        $this->repository->setCacheAdapter($cache);

        return $this;
    }

    /**
     * Cache all definitions currently known to the repository.
     */
    public function cacheAllDefinitions(bool $forceClearFirst = false): self
    {
        $this->repository->checkIfLocked();

        // If no definitions added
        if (empty($this->repository->getFunctionReference())) {
            throw new ContainerException('No definitions added.');
        }

        if (! $this->repository->getCacheAdapter()) {
            throw new ContainerException('No cache adapter set.');
        }

        if ($forceClearFirst) {
            // Attempt to clear only container-related keys
            $aliasPrefix = $this->repository->getAlias() . '-';
            $this->repository->getCacheAdapter()->clear($aliasPrefix);
        }

        // Use whichever resolver class is set in Container
        $resolverClass = $this->container->getRepositoryResolverClass();
        $resolver      = new $resolverClass($this->repository);

        // Pre-cache each definition
        foreach ($this->repository->getFunctionReference() as $id => $_definition) {
            // The resolver’s "resolveByDefinition($id)" call
            // will also store the result in repository->resolvedDefinition[...]
            $resolver->resolveByDefinition($id);
        }

        return $this;
    }

    /**
     * Optional: chain back to the Container.
     */
    public function end(): Container
    {
        return $this->container;
    }
}
