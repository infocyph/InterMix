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
 */
class OptionsManager
{
    public function __construct(
        protected Repository $repository,
        protected Container  $container
    ) {
        //
    }

    /**
     * Configure injection options for the container.
     *
     * @param  bool        $injection
     * @param  bool        $methodAttributes
     * @param  bool        $propertyAttributes
     * @param  string|null $defaultMethod
     *
     * @return $this
     *
     * @throws ContainerException if container is locked
     */
    public function setOptions(
        bool $injection = true,
        bool $methodAttributes = false,
        bool $propertyAttributes = false,
        ?string $defaultMethod = null
    ): self {
        // Ensure not locked
        $this->repository->checkIfLocked();

        // Update repository toggles
        $this->repository->enableMethodAttribute($methodAttributes);
        $this->repository->enablePropertyAttribute($propertyAttributes);
        $this->repository->setDefaultMethod($defaultMethod);

        // Switch the container’s resolver property
        $this->container->setResolverClass(
            $injection ? InjectedCall::class : GenericCall::class
        );

        return $this;
    }

    /**
     * Optionally let the user chain back to the parent Container.
     */
    public function end(): Container
    {
        return $this->container;
    }
}
