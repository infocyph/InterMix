<?php

declare(strict_types=1);

namespace Infocyph\InterMix\DI\Managers;

use Closure;
use Infocyph\InterMix\DI\Container;
use Infocyph\InterMix\DI\Data\ClassResource;
use Infocyph\InterMix\DI\Data\ConstructorMeta;
use Infocyph\InterMix\DI\Data\MethodMeta;
use Infocyph\InterMix\DI\Resolver\Repository;
use Infocyph\InterMix\Exceptions\ContainerException;

/**
 * Handles registering closures, classes, methods, and properties.
 */
class RegistrationManager
{
    /**
     * Initializes the registration manager with a repository and a container.
     *
     * @param Repository $repository The internal repository of definitions, resolved instances, etc.
     * @param Container $container The container instance to which this manager is bound.
     */
    public function __construct(
        protected Repository $repository,
        protected Container $container,
    ) {
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
        array $parameters = [],
    ): self {
        $this->repository->addClosureResource($closureAlias, $function, $parameters);
        return $this;
    }


    /**
     * Registers a class with constructor parameters.
     *
     * This method merges the constructor parameters with existing properties
     * associated with the given class in the repository. The user-supplied
     * constructor parameters take precedence over existing ones.
     *
     * @param string $class The class name for which the constructor is being registered.
     * @param array $parameters An associative array of parameters to register.
     *
     * @return self Returns the current instance for method chaining.
     * @throws ContainerException
     */
    public function registerClass(string $class, array $parameters = []): self
    {
        $existing = $this->repository->getClassResourceOf($class);

        $updated = new ClassResource(
            ctor: new ConstructorMeta(params: $parameters),
            methodMeta: $existing->methodMeta,
            properties: $existing->properties,
        );

        $this->repository->addClassResource($class, $updated);
        return $this;
    }

    /**
     * Registers a method with its parameters for a specified class.
     *
     * This method updates the class resource in the repository by adding
     * the specified method and its parameters. The newly registered method
     * information is merged with any existing class resources.
     *
     * @param string $class The class name for which the method is being registered.
     * @param string $method The method name to register.
     * @param array $parameters An associative array of parameters for the method.
     *
     * @return self Returns the current instance for method chaining.
     * @throws ContainerException
     */
    public function registerMethod(string $class, string $method, array $parameters = []): self
    {
        $existing = $this->repository->getClassResourceOf($class);

        $updated = new ClassResource(
            ctor: $existing->ctor,
            methodMeta: new MethodMeta($method, $parameters),
            properties: $existing->properties,
        );

        $this->repository->addClassResource($class, $updated);
        return $this;
    }

    /**
     * Registers properties for a specified class.
     *
     * This method merges the provided properties with existing properties
     * associated with the given class in the repository. The user-supplied
     * properties take precedence over existing ones.
     *
     * @param string $class The class name for which properties are being registered.
     * @param array $property An associative array of properties to register.
     *
     * @return self Returns the current instance for method chaining.
     * @throws ContainerException
     */
    public function registerProperty(string $class, array $property): self
    {
        $existing = $this->repository->getClassResourceOf($class);

        $updated = new ClassResource(
            ctor: $existing->ctor,
            methodMeta: $existing->methodMeta,
            properties: $property + $existing->properties, // merge (user wins)
        );

        $this->repository->addClassResource($class, $updated);
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

    /**
     * Retrieves the container instance.
     *
     * This method provides access to the Container instance,
     * allowing for the retrieval of registered resources and their associated definitions.
     *
     * @return Container The container instance.
     */
    public function end(): Container
    {
        return $this->container;
    }
}
