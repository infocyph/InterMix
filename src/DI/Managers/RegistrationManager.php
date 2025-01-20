<?php

declare(strict_types=1);

namespace Infocyph\InterMix\DI\Managers;

use Closure;
use Infocyph\InterMix\DI\Container;
use Infocyph\InterMix\DI\Resolver\Repository;

/**
 * Handles registering closures, classes, methods, and properties
 * into the Repository so they can be resolved later.
 */
class RegistrationManager
{
    public function __construct(
        protected Repository $repository,
        protected Container  $container
    ) {
        //
    }

    /**
     * Registers a closure under a specific alias, with optional parameters.
     */
    public function registerClosure(
        string $closureAlias,
        callable|Closure $function,
        array $parameters = []
    ): self {
        $this->repository->checkIfLocked();

        // Use a repository setter for closure resource
        $this->repository->addClosureResource($closureAlias, $function, $parameters);

        return $this;
    }

    /**
     * Registers a class with given parameters (for its constructor).
     */
    public function registerClass(string $class, array $parameters = []): self
    {
        $this->repository->checkIfLocked();

        // We store it in 'constructor' => [ 'on' => '__constructor', 'params' => $parameters ]
        $this->repository->addClassResource($class, 'constructor', [
            'on'     => '__constructor',
            'params' => $parameters,
        ]);

        return $this;
    }

    /**
     * Registers a method for a given class with optional parameters.
     */
    public function registerMethod(
        string $class,
        string $method,
        array $parameters = []
    ): self {
        $this->repository->checkIfLocked();

        $this->repository->addClassResource($class, 'method', [
            'on'     => $method,
            'params' => $parameters,
        ]);

        return $this;
    }

    /**
     * Registers a property (or multiple) for a given class.
     * Merges with any existing property definitions.
     */
    public function registerProperty(string $class, array $property): self
    {
        $this->repository->checkIfLocked();

        // Combine with existing property array
        $existing = $this->repository->getClassResource()[$class]['property'] ?? [];
        $merged   = array_merge($existing, $property);

        $this->repository->addClassResource($class, 'property', $merged);

        return $this;
    }

    /**
     * Optional: let the user chain back to the parent Container.
     */
    public function end(): Container
    {
        return $this->container;
    }
}
