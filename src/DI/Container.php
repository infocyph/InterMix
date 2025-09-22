<?php

declare(strict_types=1);

namespace Infocyph\InterMix\DI;

use ArrayAccess;
use Closure;
use Exception;
use Infocyph\InterMix\DI\Attribute\AttributeRegistry;
use Infocyph\InterMix\DI\Invoker\GenericCall;
use Infocyph\InterMix\DI\Invoker\InjectedCall;
use Infocyph\InterMix\DI\Managers\DefinitionManager;
use Infocyph\InterMix\DI\Managers\InvocationManager;
use Infocyph\InterMix\DI\Managers\OptionsManager;
use Infocyph\InterMix\DI\Managers\RegistrationManager;
use Infocyph\InterMix\DI\Resolver\Repository;
use Infocyph\InterMix\DI\Support\ContainerProxy;
use Infocyph\InterMix\DI\Support\DebugTracer;
use Infocyph\InterMix\DI\Support\TraceLevelEnum;
use Infocyph\InterMix\Exceptions\ContainerException;
use Infocyph\InterMix\Exceptions\NotFoundException;
use InvalidArgumentException;
use Psr\Container\ContainerInterface;
use ReflectionException;
use Throwable;

final class Container implements ContainerInterface, ArrayAccess
{
    use ContainerProxy;

    protected static array $instances = [];
    protected DefinitionManager $definitionManager;
    protected InvocationManager $invocationManager;
    protected OptionsManager $optionsManager;
    protected RegistrationManager $registrationManager;
    protected Repository $repository;
    protected closure|InjectedCall|GenericCall $resolver;

    /**
     * Container constructor.
     *
     * @param string $instanceAlias Optional alias for this Container instance.
     *                               Defaults to 'default'.
     * @throws ContainerException
     */
    public function __construct(private readonly string $instanceAlias = __DIR__)
    {
        self::$instances[$this->instanceAlias] ??= $this;
        $this->repository = new Repository($this);
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
    public static function instance(string $instanceAlias = __DIR__): self
    {
        return self::$instances[$instanceAlias] ??= new self($instanceAlias);
    }

    /**
     * Retrieve the attribute registry.
     *
     * The attribute registry is responsible for managing and resolving attribute
     * definitions and their corresponding resolvers. This method provides access
     * to the attribute registry associated with the repository.
     *
     * @return AttributeRegistry The attribute registry instance.
     */
    public function attributeRegistry(): AttributeRegistry
    {
        return $this->repository->attributeRegistry();
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
     * Debugs the service resolution process for a given ID.
     *
     * This method attempts to retrieve the service instance for the given ID,
     * and returns the current debug trace. If any errors occur during the
     * service resolution process, they are caught and ignored, as the goal
     * here is to obtain a debug trace, not to actually use the service.
     *
     * @param string $id The ID of the service to debug.
     * @return array The debug trace for the service resolution process.
     */
    public function debug(string $id): array
    {
        try {
            $tracer = $this->repository->tracer();
            $tracer->setCaptureLocation(true);
            $tracer->setLevel(TraceLevelEnum::Verbose);
            $this->get($id);
        } catch (Throwable) {
            // swallow; we still want trace
        }
        return $this->repository->tracer()->toArray();
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
     * Finds and retrieves all service definitions tagged with a specified tag.
     *
     * This method iterates over the repository's definition metadata,
     * checking each definition's tags for a match against the provided tag.
     * If a match is found, the service definition is resolved and added to
     * the result array.
     *
     * @param string $tag The tag to search for among service definitions.
     * @return array An array of resolved service definitions matching the provided tag.
     * @throws \Psr\Cache\InvalidArgumentException
     */
    public function findByTag(string $tag): array
    {
        $matches = [];
        foreach ($this->repository->getAllDefinitionMeta() as $id => $meta) {
            if (in_array($tag, $meta['tags'], true)) {
                $matches[$id] = $this->get($id);
            }
        }
        return $matches;
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
     * INTERNAL – tooling helper, *not* a public API promise.
     *
     * @internal
     */
    public function getRepository(): Repository
    {
        return $this->repository;
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
     * Parse a callable string/array, returning an associative array with keys
     * "kind" and one of "closure", "class", "method", or "function".
     *
     * The following syntaxes are supported:
     * - "class@method"
     * - "class::method"
     * - class name as a string
     * - function name as a string
     * - callable object (e.g. closure, invokable class)
     * - array of class and method name
     *
     * @param string|array|Closure|callable $spec The callable string/array to parse.
     * @return array An associative array with keys "kind" and one of "closure", "class", "method", or "function".
     * @throws ContainerException If the callable spec is invalid.
     * @throws InvalidArgumentException If no argument is provided.
     */
    public function parseCallable(string|array|Closure|callable $spec): array
    {
        if (empty($spec)) {
            throw new InvalidArgumentException('No argument provided!');
        }

        return match (true) {
            $spec instanceof Closure
            => ['kind' => 'closure', 'closure' => $spec],

            is_array($spec) && count($spec) === 2 && is_string($spec[0]) && is_string($spec[1])
            => ['kind' => 'method', 'class' => $spec[0], 'method' => $spec[1]],

            is_string($spec) => match (true) {
                str_contains($spec, '@')
                => (static function () use ($spec) {
                    [$cls, $m] = explode('@', $spec, 2);
                    return ['kind' => 'method', 'class' => $cls, 'method' => $m];
                })(),

                str_contains($spec, '::')
                => (static function () use ($spec) {
                    [$cls, $m] = explode('::', $spec, 2);
                    return ['kind' => 'method', 'class' => $cls, 'method' => $m];
                })(),

                class_exists($spec)
                => ['kind' => 'class', 'class' => $spec],

                function_exists($spec)
                => ['kind' => 'function', 'function' => $spec],

                default
                => throw new ContainerException(
                    sprintf(
                        "Unknown callable string '%s'. Expected 'class@method', 'class::method', class, or function.",
                        $spec
                    )
                ),
            },

            // objects with __invoke, static callables, etc.
            is_callable($spec)
            => ['kind' => 'closure', 'closure' => $spec],

            default
            => throw new ContainerException(
                sprintf(
                    "Unknown callable spec for '%s'. Expected closure/callable, 'class@method', 'class::method', [class,method], class, or function.",
                    is_string($spec) ? $spec : gettype($spec)
                )
            ),
        };
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
     * Register the spec and immediately resolve/return the result.
     *
     * Mirrors RegistrationManager signatures exactly:
     * - registerClosure(string $alias, callable $fn, array $params = [])
     * - registerClass(string $class, array $params = [])
     * - registerMethod(string $class, string $method, array $params = [])
     *
     * @param string|Closure|callable|array|null $spec
     * @param array $parameters
     * @return mixed
     * @throws ContainerException|\ReflectionException|InvalidArgumentException
     */
    public function resolveNow(
        string|Closure|callable|array|null $spec,
        array $parameters = [],
    ): mixed {
        if ($spec === null) {
            return $this;
        }

        $desc = $this->parseCallable($spec);

        return match ($desc['kind']) {
            'closure' => (function () use ($desc, $parameters) {
                /** @var callable $cb */
                $cb = $desc['closure'];
                $id = random_bytes(5);
                $this->registration()->registerClosure($id, $cb, $parameters);
                return $this->getReturn($id);
            })(),

            'function' => (function () use ($desc, $parameters) {
                /** @var non-empty-string $fn */
                $fn = $desc['function'];
                $this->registration()->registerClosure($fn, $fn, $parameters);
                return $this->getReturn($fn);
            })(),

            'class' => (function () use ($desc, $parameters) {
                $this->registration()->registerClass($desc['class'], $parameters);
                return $this->getReturn($desc['class']);
            })(),

            'method' => (function () use ($desc, $parameters) {
                $this->registration()->registerMethod($desc['class'], $desc['method'], $parameters);
                return $this->getReturn($desc['class']);
            })(),
        };
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
     * Retrieves the debug tracer instance.
     *
     * This method returns the debug tracer associated with the container.
     * The tracer is used to track and log the execution flow and
     * interactions within the container, aiding in debugging and
     * tracing the service resolution process.
     *
     * @return DebugTracer The debug tracer instance.
     */
    public function tracer(): DebugTracer
    {
        return $this->repository->tracer();
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
            default => new ContainerException(
                "Error retrieving entry '$id': " . $exception->getMessage(),
                0,
                $exception,
            ),
        };
    }
}
