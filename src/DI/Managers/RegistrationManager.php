<?php

declare(strict_types=1);

namespace Infocyph\InterMix\DI\Managers;

use ArrayAccess;
use Closure;
use Infocyph\InterMix\DI\Container;
use Infocyph\InterMix\DI\Support\ServiceProviderInterface;
use Infocyph\InterMix\DI\Resolver\Repository;
use Infocyph\InterMix\Exceptions\ContainerException;

/**
 * Handles registering closures, classes, methods, and properties.
 */
class RegistrationManager implements ArrayAccess
{
    use ManagerProxy;

    /**
     * Initializes the registration manager with a repository and a container.
     *
     * @param Repository $repository The internal repository of definitions, resolved instances, etc.
     * @param Container  $container  The container instance to which this manager is bound.
     */
    public function __construct(
        protected Repository $repository,
        protected Container  $container
    ) {
    }

    /**
     * Imports a service provider into the container.
     *
     * The provider may be passed as a class name (string) or an instance of
     * ServiceProviderInterface. The provider is then registered with the
     * container, and its definitions are added to the container.
     *
     * @param string|ServiceProviderInterface $provider The service provider to import.
     *
     * @return static The registration manager instance.
     * @throws ContainerException
     */
    public function import(string|ServiceProviderInterface $provider): self
    {
        if (is_string($provider)) {
            $provider = new $provider();
        }

        if (!$provider instanceof ServiceProviderInterface) {
            throw new ContainerException(
                'Service-provider must implement ServiceProviderInterface.'
            );
        }

        $provider->register($this->container);
        return $this;
    }

    /**
     * Registers a closure with associated parameters.
     *
     * @param string $closureAlias The alias under which the closure will be stored.
     * @param callable|Closure $function The closure to be registered.
     * @param array $parameters Any parameters to be passed to the closure.
     *
     * @return $this
     * @throws ContainerException
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
     * Registers a class with associated constructor parameters.
     *
     * This method stores the constructor parameters for the specified class in the repository,
     * allowing the container to resolve and instantiate the class with the provided parameters.
     *
     * @param string $class The name of the class to register.
     * @param array $parameters An array of parameters to be passed to the class constructor.
     *
     * @return $this
     * @throws ContainerException
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
     * Registers a method with associated parameters for a given class.
     *
     * This method stores the method name and its parameters in the repository,
     * allowing the container to resolve and invoke the method with the provided parameters
     * when the class is instantiated.
     *
     * @param string $class The name of the class whose method is being registered.
     * @param string $method The name of the method to register.
     * @param array $parameters An array of parameters to be passed to the method.
     *
     * @return $this
     * @throws ContainerException
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
     * Registers a property with associated parameters for a given class.
     *
     * This method stores the property name and its parameters in the repository,
     * allowing the container to resolve and set the property with the provided parameters
     * when the class is instantiated.
     *
     * @param string $class The name of the class whose property is being registered.
     * @param array $property An array of property names as keys and their associated values as values.
     *
     * @return $this
     * @throws ContainerException
     */
    public function registerProperty(string $class, array $property): self
    {
        // Merge with existing
        $existing = $this->repository->getClassResource()[$class]['property'] ?? [];
        $merged   = array_merge($existing, $property);

        $this->repository->addClassResource($class, 'property', $merged);
        return $this;
    }

    /**
     * Retrieves the definition manager associated with the container.
     *
     * This method provides access to the DefinitionManager instance,
     * allowing for the management and retrieval of definitions within the container.
     *
     * @return DefinitionManager The instance managing definitions.
     */
    public function definitions(): DefinitionManager
    {
        return $this->container->definitions();
    }

    /**
     * Retrieves the options manager associated with the container.
     *
     * This method provides access to the OptionsManager instance,
     * allowing for the management and retrieval of options within the container.
     *
     * @return OptionsManager The instance managing options.
     */
    public function options(): OptionsManager
    {
        return $this->container->options();
    }

    /**
     * Retrieves the invocation manager associated with the container.
     *
     * This method provides access to the InvocationManager instance,
     * allowing for the management and retrieval of invocations within the container.
     *
     * @return InvocationManager The instance managing invocations.
     */
    public function invocation(): InvocationManager
    {
        return $this->container->invocation();
    }
}
