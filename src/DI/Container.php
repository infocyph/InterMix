<?php

declare(strict_types=1);

namespace Infocyph\InterMix\DI;

use Closure;
use Exception;
use Infocyph\InterMix\DI\Invoker\GenericCall;
use Infocyph\InterMix\DI\Invoker\InjectedCall;
use Infocyph\InterMix\DI\Managers\DefinitionManager;
use Infocyph\InterMix\DI\Managers\InvocationManager;
use Infocyph\InterMix\DI\Managers\OptionsManager;
use Infocyph\InterMix\DI\Managers\RegistrationManager;
use Infocyph\InterMix\DI\Resolver\Repository;
use Infocyph\InterMix\Exceptions\ContainerException;
use Infocyph\InterMix\Exceptions\NotFoundException;
use InvalidArgumentException;
use Psr\Container\ContainerInterface;
use ReflectionException;

class Container implements ContainerInterface
{
    /**
     * Registry of named container instances
     *
     * @var array<string, static>
     */
    protected static array $instances = [];

    /**
     * Shared repository
     */
    protected Repository $repository;

    /**
     * Resolver class name (InjectedCall::class or GenericCall::class or custom).
     *
     * @var closure|GenericCall|InjectedCall
     */
    protected closure|InjectedCall|GenericCall $resolver;

    /**
     * Sub-managers
     */
    protected DefinitionManager $definitionManager;

    protected RegistrationManager $registrationManager;

    protected OptionsManager $optionsManager;

    protected InvocationManager $invocationManager;

    /**
     * Container constructor.
     *
     * @param string $instanceAlias Optional alias for this Container instance.
     *                               Defaults to 'default'.
     * @throws ContainerException
     */
    public function __construct(private readonly string $instanceAlias = 'intermix')
    {
        self::$instances[$this->instanceAlias] ??= $this;
        $this->repository = new Repository();
        $this->repository->setAlias($this->instanceAlias);
        $this->repository->setFunctionReference(
            ContainerInterface::class,
            $this,
        );
        $this->resolver = fn () => new InjectedCall($this->repository);
        $this->definitionManager = new DefinitionManager($this->repository, $this);
        $this->registrationManager = new RegistrationManager($this->repository, $this);
        $this->optionsManager = new OptionsManager($this->repository, $this);
        $this->invocationManager = new InvocationManager($this->repository, $this);
    }


    /**
     * Gets or creates a container instance by alias.
     *
     * If a container with the given alias already exists, it is returned.
     * Otherwise, a new container instance is created and stored in the registry.
     *
     * @param string $instanceAlias The alias of the container instance to get or create.
     *                               Defaults to 'default'.
     *
     * @return static The container instance.
     * @throws ContainerException
     */
    public static function instance(string $instanceAlias = 'default'): static
    {
        return self::$instances[$instanceAlias] ??= new static($instanceAlias);
    }


    /**
     * Remove the container instance from the registry.
     *
     * This method removes the container instance from the internal registry
     * and makes it eligible for garbage collection. This is useful if you
     * want to ensure that the container instance is no longer referenced
     * after it has been used.
     *
     * @return void
     */
    public function unset(): void
    {
        unset(self::$instances[$this->instanceAlias]);
    }


    /**
     * Locks the container from future modifications.
     *
     * Once the container is locked, no more definitions, values, or options can be set.
     * This method is useful for tests or other scenarios where you want to ensure that
     * the container does not change after it has been configured.
     *
     * @return $this The container instance.
     */
    public function lock(): self
    {
        // Let the repository handle the lock
        $this->repository->lock();

        return $this;
    }


    /**
     * Retrieves a value from the container.
     *
     * The container will first try to find a definition matching the given ID.
     * If a matching definition is found, the container will attempt to resolve
     * the definition and return the result. If no matching definition is found,
     * the container may attempt to auto-resolve the ID if it is a class or
     * closure.
     *
     * @param string $id The ID of the value to retrieve.
     *
     * @return mixed The retrieved value.
     * @throws Exception|\Psr\Cache\InvalidArgumentException If the container is unable to retrieve the value.
     */
    public function get(string $id): mixed
    {
        try {
            return $this->invocationManager->get($id);
        } catch (Exception $exception) {
            throw $this->wrapException($exception, $id);
        }
    }


    /**
     * Checks if a definition ID exists in the container.
     *
     * This method attempts to verify the existence of a given ID
     * within the container by delegating the check to the
     * InvocationManager. If an exception occurs during the check,
     * the method will return false.
     *
     * @param string $id The ID of the definition to check.
     * @return bool True if the definition ID exists, false otherwise.
     */
    public function has(string $id): bool
    {
        try {
            return $this->invocationManager->has($id);
        } catch (Exception) {
            return false;
        }
    }


    /**
     * Retrieve the definition manager.
     *
     * The definition manager is responsible for storing and retrieving
     * definitions from the container. It provides methods for adding,
     * retrieving, and removing definitions.
     */
    public function definitions(): DefinitionManager
    {
        return $this->definitionManager;
    }


    /**
     * Retrieve the registration manager.
     *
     * The registration manager is responsible for registering closures, classes, methods, and properties
     * with the container. It provides methods for registering each of these types of definitions.
     *
     * @return RegistrationManager The registration manager.
     */
    public function registration(): RegistrationManager
    {
        return $this->registrationManager;
    }


    /**
     * Retrieve the options manager.
     *
     * The options manager is responsible for setting toggles such as injection,
     * method attributes, property attributes, environment, debug, and lazy
     * loading.
     *
     * @return OptionsManager The options manager.
     */
    public function options(): OptionsManager
    {
        return $this->optionsManager;
    }


    /**
     * Retrieve the invocation manager.
     *
     * The invocation manager is responsible for resolving definitions into values,
     * and for calling methods and functions with the resolved values as arguments.
     *
     * @return InvocationManager The invocation manager.
     */
    public function invocation(): InvocationManager
    {
        return $this->invocationManager;
    }


    /**
     * Retrieves the class name of the current resolver being used by the repository.
     *
     * @return object The class name of the resolver.
     */
    public function getCurrentResolver(): object
    {
        if ($this->resolver instanceof Closure) {
            $this->resolver = ($this->resolver)();
        }
        return $this->resolver;
    }

    /**
     * Sets the class name of the resolver to be used by the repository.
     *
     * This method allows for dynamically changing the resolver class
     * used in the container. The new resolver class should be a valid
     * fully qualified class name that implements the required interface
     * for resolvers.
     *
     * @param string $resolverClass The fully qualified class name of the new resolver.
     * @return void
     */
    public function setResolverClass(string $resolverClass): void
    {
        $this->resolver = fn () => new $resolverClass($this->repository);
    }

    /**
     * Resolves a class with method name (if provided) and executes the method with optional parameters.
     *
     * This method is a convenience wrapper for the InvocationManager's call() method.
     *
     * @param string|Closure|callable $classOrClosure The class name or closure to be resolved and executed.
     * @param string|bool|null $method The method to call on the resolved class (or null if no method should be called).
     * @return mixed The result of executing the resolved class or closure.
     * @throws ContainerException
     * @throws ReflectionException|\Psr\Cache\InvalidArgumentException
     */
    public function call(string|Closure|callable $classOrClosure, string|bool|null $method = null): mixed
    {
        return $this->invocationManager->call($classOrClosure, $method);
    }

    /**
     * Creates a new instance of the given class with dependency injection
     * and optionally calls a method on the instance.
     *
     * This method is a convenience wrapper for the InvocationManager's
     * make() method, providing the ability to create objects with their
     * dependencies injected and optionally execute a specified method.
     *
     * @param string $class The class name to create a new instance of.
     * @param string|bool $method The method to call on the instance, or false to not call a method.
     * @return mixed The newly created instance, or the result of the called method.
     * @throws ContainerException
     * @throws ReflectionException
     */
    public function make(string $class, string|bool $method = false): mixed
    {
        return $this->invocationManager->make($class, $method);
    }

    /**
     * Resolves a definition ID and returns the result of the resolved instance.
     *
     * If the resolved instance is a closure, it is called with no arguments and
     * the result is returned. Otherwise, the resolved instance itself is returned.
     *
     * @param string $id The ID of the definition to resolve and return.
     *
     * @return mixed The result of the resolved instance, or the resolved instance itself.
     * @throws ContainerException
     * @throws ReflectionException
     * @throws \Psr\Cache\InvalidArgumentException
     */
    public function getReturn(string $id): mixed
    {
        return $this->invocationManager->getReturn($id);
    }

    /**
     * Sets the environment for the container.
     *
     * This method allows setting the environment, which can be used
     * to resolve environment-based interface mappings. It delegates
     * the environment setting to the repository.
     *
     * @param string $env The environment name.
     * @return self The container instance for method chaining.
     * @throws ContainerException
     */
    public function setEnvironment(string $env): self
    {
        $this->repository->setEnvironment($env);

        return $this;
    }

    /**
     * Enables or disables lazy loading for the container.
     *
     * When lazy loading is enabled, services or definitions
     * are not resolved until explicitly requested for the first time.
     * This can improve performance by deferring the initialization
     * of services until they are actually needed.
     *
     * @param bool $lazy Whether to enable lazy loading. Defaults to true.
     * @return self The container instance for method chaining.
     * @throws ContainerException
     */
    public function enableLazyLoading(bool $lazy = true): self
    {
        $this->repository->enableLazyLoading($lazy);

        return $this;
    }


    /**
     * Splits a given class and method representation into a callable array format.
     *
     * This method takes an input that can be a string, array, closure, or callable,
     * and attempts to break it down into a recognizable callable format such as
     * ['class', 'method'] or [closure, null]. It handles various formats including:
     * - Closures or string representations of global functions or classes (returns [that, null])
     * - Arrays with two elements representing [class, method]
     * - Strings with "@" (e.g., "Class@method") or "::" (e.g., "Class::method")
     *
     * @param string|array|Closure|callable $classAndMethod The class and method representation to be split.
     * @return array An array containing the class and method in a callable format.
     *
     * @throws InvalidArgumentException If the provided argument is empty.
     * @throws ContainerException If the format of the provided argument is unrecognized.
     */
    public function split(string|array|Closure|callable $classAndMethod): array
    {
        if (empty($classAndMethod)) {
            throw new InvalidArgumentException('No argument provided!');
        }

        // (1) If closure or a string that is a class_exists or function_exists,
        //     or a general is_callable, we return [that, null].
        //     This handles e.g. closures or "ClassName" with no method or a global function name.
        if ($classAndMethod instanceof Closure ||
            (is_string($classAndMethod) && (class_exists($classAndMethod) || function_exists($classAndMethod))) ||
            is_callable($classAndMethod)
        ) {
            return [$classAndMethod, null];
        }

        // (2) If it's an array with 2 elements => [class, method]
        if (is_array($classAndMethod) && count($classAndMethod) === 2) {
            return [$classAndMethod[0], $classAndMethod[1]];
        }

        // (3) If it's a string with "@" => "Class@method"
        if (is_string($classAndMethod) && str_contains($classAndMethod, '@')) {
            return explode('@', $classAndMethod, 2);
        }

        // (4) If it's a string with "::" => "Class::method"
        if (is_string($classAndMethod) && str_contains($classAndMethod, '::')) {
            return explode('::', $classAndMethod, 2);
        }

        throw new ContainerException(
            sprintf(
                "Unknown Class & Method format for '%s'. Expected closure/callable, 'class@method', 'class::method', or [class,method].",
                is_string($classAndMethod) ? $classAndMethod : gettype($classAndMethod),
            ),
        );
    }


    /**
     * Wraps an exception into a NotFoundException if it's a NotFoundException,
     * or a ContainerException with the given ID otherwise.
     *
     * @param Exception $exception The exception to wrap.
     * @param string $id The ID of the entry that caused the exception.
     * @return Exception The wrapped exception.
     */
    private function wrapException(Exception $exception, string $id): Exception
    {
        return match (true) {
            $exception instanceof NotFoundException => new NotFoundException("No entry found for '$id'", 0, $exception),
            default => new ContainerException("Error retrieving entry '$id': " . $exception->getMessage(), 0, $exception),
        };
    }
}
