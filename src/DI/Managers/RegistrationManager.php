<?php

declare(strict_types=1);

namespace Infocyph\InterMix\DI\Managers;

use Closure;
use Infocyph\InterMix\DI\Container;
use Infocyph\InterMix\DI\Resolver\Repository;

/**
 * Handles registering closures, classes, methods, and properties.
 */
class RegistrationManager
{
    public function __construct(
        protected Repository $repository,
        protected Container  $container
    ) {
    }

    /**
     * Registers a closure alias with optional parameters.
     */
    public function registerClosure(
        string $closureAlias,
        callable|Closure $function,
        array $parameters = []
    ): self {
        $this->repository->addClosureResource($closureAlias, $function, $parameters);
        return $this;
    }

    /**
     * Registers a class with constructor parameters.
     */
    public function registerClass(string $class, array $parameters = []): self
    {
        $this->repository->addClassResource($class, 'constructor', [
            'on'     => '__constructor',
            'params' => $parameters,
        ]);
        return $this;
    }

    /**
     * Registers a method for a given class with parameters.
     */
    public function registerMethod(
        string $class,
        string $method,
        array $parameters = []
    ): self {
        $this->repository->addClassResource($class, 'method', [
            'on'     => $method,
            'params' => $parameters,
        ]);
        return $this;
    }

    /**
     * Registers one or more properties for a given class.
     */
    public function registerProperty(string $class, array $property): self
    {
        // Merge with existing
        $existing = $this->repository->getClassResource()[$class]['property'] ?? [];
        $merged   = array_merge($existing, $property);

        $this->repository->addClassResource($class, 'property', $merged);
        return $this;
    }

    public function definitions(): DefinitionManager
    {
        return $this->container->definitions();
    }

    public function options(): OptionsManager
    {
        return $this->container->options();
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
