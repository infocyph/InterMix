<?php

declare(strict_types=1);

namespace Infocyph\InterMix\DI\Managers;

use Infocyph\InterMix\DI\Container;
use Infocyph\InterMix\DI\Invoker\GenericCall;
use Infocyph\InterMix\DI\Invoker\InjectedCall;
use Infocyph\InterMix\DI\Resolver\Repository;
use Infocyph\InterMix\Exceptions\ContainerException;

/**
 * Handles toggling injection, method attributes, property attributes, etc.
 * Optionally environment / debug toggles if you want them all in one place.
 */
class OptionsManager
{
    public function __construct(
        protected Repository $repository,
        protected Container  $container
    ) {
    }

    /**
     * Basic DI toggles (like before).
     *
     * @throws ContainerException if locked
     */
    public function setOptions(
        bool $injection = true,
        bool $methodAttributes = false,
        bool $propertyAttributes = false,
        ?string $defaultMethod = null
    ): self {
        // The repository does its own lock check
        $this->repository->enableMethodAttribute($methodAttributes);
        $this->repository->enablePropertyAttribute($propertyAttributes);
        $this->repository->setDefaultMethod($defaultMethod);

        // Switch container’s $resolver
        $this->container->setResolverClass(
            $injection ? InjectedCall::class : GenericCall::class
        );
        return $this;
    }

    /**
     * Optionally handle environment, debug, lazy toggles here instead of calling container directly.
     */
    public function setEnvironment(string $env): self
    {
        $this->repository->setEnvironment($env);
        return $this;
    }

    public function enableDebug(bool $enabled = true): self
    {
        $this->repository->setDebug($enabled);
        return $this;
    }

    public function enableLazyLoading(bool $lazy = true): self
    {
        $this->repository->enableLazyLoading($lazy);
        return $this;
    }

    public function definitions(): DefinitionManager
    {
        return $this->container->definitions();
    }

    public function registration(): RegistrationManager
    {
        return $this->container->registration();
    }

    public function invocation(): InvocationManager
    {
        return $this->container->invocation();
    }

    public function end(): Container
    {
        return $this->container;
    }
}
