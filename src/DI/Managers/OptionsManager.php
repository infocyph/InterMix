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
    /**
     * Constructs an OptionsManager.
     *
     * @param Repository $repository The repository instance for managing definitions and instances.
     * @param Container $container The container this manager is associated with.
     */
    public function __construct(
        protected Repository $repository,
        protected Container  $container
    ) {
    }


    /**
     * Set container options, such as enabling/disabling injection, method
     * attributes, and property attributes, and setting a default method.
     *
     * The container will not be modified if it is locked.
     *
     * @param bool $injection Whether to enable injection. Defaults to true.
     * @param bool $methodAttributes Whether to enable method attributes. Defaults to false.
     * @param bool $propertyAttributes Whether to enable property attributes. Defaults to false.
     * @param string|null $defaultMethod The default method to call if none is specified. Defaults to null.
     *
     * @return $this
     * @throws ContainerException
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
     * Sets the environment for the container.
     *
     * This method allows you to specify the environment name, which can be
     * used for environment-specific configurations or bindings.
     *
     * @param string $env The name of the environment to set.
     * @return $this
     * @throws ContainerException
     */
    public function setEnvironment(string $env): self
    {
        $this->repository->setEnvironment($env);
        return $this;
    }

    /**
     * Enable or disable debug mode.
     *
     * If debug mode is enabled, the container will log additional information
     * about the resolution process. This can be useful for debugging purposes.
     *
     * @param bool $enabled Whether to enable debug mode.
     *
     * @return $this
     */
    public function enableDebug(bool $enabled = true): self
    {
        $this->repository->setDebug($enabled);
        return $this;
    }

    /**
     * Enables or disables lazy loading for the container.
     *
     * If lazy loading is enabled, the container will only resolve definitions
     * when they are explicitly requested. This can improve performance by
     * avoiding unnecessary resolutions. If lazy loading is disabled, the
     * container will resolve all definitions immediately.
     *
     * @param bool $lazy Whether to enable lazy loading. Defaults to true.
     *
     * @return $this
     * @throws ContainerException
     */
    public function enableLazyLoading(bool $lazy = true): self
    {
        $this->repository->enableLazyLoading($lazy);
        return $this;
    }

    /**
     * Binds a concrete implementation to an interface for a specific environment.
     *
     * The given interface will be resolved to the given concrete implementation
     * only if the current environment matches the given environment.
     *
     * @param string $env the environment for which the binding should be applied
     * @param string $interface the interface to bind
     * @param string $concrete the concrete implementation to bind to
     *
     * @return $this
     * @throws ContainerException if the container is locked
     */
    public function bindInterfaceForEnv(string $env, string $interface, string $concrete): self
    {
        $this->repository->bindInterfaceForEnv($env, $interface, $concrete);
        return $this;
    }

    /**
     * Returns the definition manager for the container.
     *
     * The definition manager is the central hub for all definitions,
     * and provides methods for retrieving, adding, and modifying
     * definitions.
     *
     * @return DefinitionManager The definition manager for the container.
     */
    public function definitions(): DefinitionManager
    {
        return $this->container->definitions();
    }

    /**
     * Returns the registration manager for the container.
     *
     * The registration manager is used to register definitions, and
     * provides methods for registering classes, methods, and properties.
     *
     * @return RegistrationManager The registration manager for the container.
     */
    public function registration(): RegistrationManager
    {
        return $this->container->registration();
    }

    /**
     * Returns the invocation manager for the container.
     *
     * The invocation manager is responsible for resolving definitions and
     * calling methods or functions with the correct parameters.
     *
     * @return InvocationManager The invocation manager for the container.
     */
    public function invocation(): InvocationManager
    {
        return $this->container->invocation();
    }

    /**
     * Ends the chain of method calls and returns the main container instance.
     *
     * This method is typically used to finalize configurations or registrations
     * and retrieve the container instance for further operations.
     *
     * @return Container The main container instance.
     */
    public function end(): Container
    {
        return $this->container;
    }
}
