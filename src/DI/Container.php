<?php

declare(strict_types=1);

namespace Infocyph\InterMix\DI;

use Closure;
use Exception;
use Infocyph\InterMix\DI\Invoker\GenericCall;
use Infocyph\InterMix\DI\Invoker\InjectedCall;
use Infocyph\InterMix\DI\Resolver\Repository;
use Infocyph\InterMix\Exceptions\ContainerException;
use Infocyph\InterMix\Exceptions\NotFoundException;
use InvalidArgumentException;
use Psr\Container\ContainerInterface;
use Symfony\Contracts\Cache\CacheInterface;

/**
 * Dependency Injector
 */
class Container implements ContainerInterface
{
    protected static array $instances = [];

    protected Repository $repository;

    protected string $resolver;

    /**
     * Constructor for the Container class.
     *
     * Initializes a new container instance and stores it in the static registry
     * using the provided alias. Sets up the repository and resolver for the container.
     *
     * @param  string  $instanceAlias  The alias for the container instance. Defaults to 'default'.
     */
    public function __construct(private readonly string $instanceAlias = 'default')
    {
        self::$instances[$this->instanceAlias] ??= $this;
        $this->repository = new Repository();
        $this->repository->functionReference = [ContainerInterface::class => $this];
        $this->repository->alias = $this->instanceAlias;
        $this->resolver = InjectedCall::class;
    }

    /**
     * Retrieves the container instance associated with the specified alias.
     *
     * If no instance exists for the given alias, a new one is created and stored.
     *
     * @param  string  $instanceAlias  The alias of the container instance to retrieve.
     * @return Container The container instance associated with the alias.
     */
    public static function instance(string $instanceAlias = 'default'): Container
    {
        return self::$instances[$instanceAlias] ??= new self($instanceAlias);
    }

    /**
     * Removes the instance from the container registry.
     */
    public function unset(): void
    {
        unset(self::$instances[$this->instanceAlias]);
    }

    /**
     * Locks the container. This prevents any further definitions from being added, modified or removed.
     *
     * @return $this
     */
    public function lock(): self
    {
        $this->repository->isLocked = true;

        return $this;
    }

    /**
     * Adds multiple definitions to the container.
     *
     * This method can be used to bulk-add definitions to the container.
     * The definitions are stored in the container and can be accessed using the passed identifiers.
     *
     * @param  array  $definitions  The definitions to store, where each key is the identifier under which the definition is stored,
     *                              and the value is the definition to store.
     * @return $this
     *
     * @throws ContainerException
     */
    public function addDefinitions(array $definitions): self
    {
        foreach ($definitions as $id => $definition) {
            $this->bind($id, $definition);
        }

        return $this;
    }

    /**
     * Adds a definition to the container.
     *
     * A definition can be a Closure, an object, a string (which is interpreted as a class name), or null.
     * The definition is stored in the container and can be accessed using the passed identifier.
     *
     * @param  string  $id  The identifier under which the definition is stored.
     * @param  mixed  $definition  The definition to store.
     * @return $this
     *
     * @throws ContainerException If the container is locked and cannot be modified.
     * @throws ContainerException If the id and definition are the same.
     */
    public function bind(string $id, mixed $definition): self
    {
        $this->repository->checkIfLocked();
        if ($id === $definition) {
            throw new ContainerException("Id and definition cannot be the same ($id)");
        }
        $this->repository->functionReference[$id] = $definition;

        return $this;
    }

    /**
     * Enable caching of definitions. If a definition is requested and the cache contains it,
     * the cached definition is returned instead of resolving the definition again.
     *
     *
     * @return static
     */
    public function enableDefinitionCache(CacheInterface $cache): self
    {
        $this->repository->cacheAdapter = $cache;

        return $this;
    }

    /**
     * Caches all definitions registered with the container.
     *
     * If `$forceClearFirst` is `true`, it will clear the cache before caching all definitions.
     *
     *
     *
     * @throws ContainerException If no definitions are added.
     * @throws ContainerException|\Psr\Cache\InvalidArgumentException If no cache adapter is set.
     */
    public function cacheAllDefinitions(bool $forceClearFirst = false): self
    {
        if (empty($this->repository->functionReference)) {
            throw new ContainerException('No definitions added.');
        }

        if (! isset($this->repository->cacheAdapter)) {
            throw new ContainerException('No cache adapter set.');
        }

        if ($forceClearFirst) {
            $this->repository->cacheAdapter->clear($this->repository->alias.'-');
        }

        $resolver = new $this->resolver($this->repository);
        foreach ($this->repository->functionReference as $id => $definition) {
            $this->repository->resolvedDefinition[$id] = $this->repository->cacheAdapter->get(
                $this->repository->alias.'-'.base64_encode($id),
                fn () => $resolver->resolveByDefinition($id)
            );
        }

        return $this;
    }

    /**
     * Retrieves the returned value of the resolved instance or definition for the given identifier.
     *
     * If the resolved instance or definition is a function or an invokable object, it calls it
     * and returns the value returned from the call. If the resolved instance or definition is
     * not a function or an invokable object, it returns the resolved instance or definition
     * itself.
     *
     * @param  string  $id  The identifier of the instance or definition to retrieve the returned
     *                      value of.
     * @return mixed The returned value of the resolved instance or definition.
     *
     * @throws Exception If the resolution process fails.
     */
    public function getReturn(string $id): mixed
    {
        try {
            $resolved = $this->get($id);
            $resource = $this->repository->resolved[$id] ?? [];

            return $resource['returned'] ?? $resolved;
        } catch (Exception $exception) {
            throw $this->wrapException($exception, $id);
        }
    }

    /**
     * Retrieves the resolved instance or definition for the given identifier.
     *
     * This method first checks if the identifier has already been resolved
     * and is available in the resolved repository. If so, it returns the
     * instance or resolved value. If the identifier is registered as a
     * definition, it resolves it using the current resolver. Otherwise,
     * it attempts to resolve the identifier by calling it.
     *
     * @param  string  $id  The identifier of the instance or definition to retrieve.
     * @return mixed The resolved instance or definition.
     *
     * @throws Exception If the resolution process fails.
     */
    public function get(string $id): mixed
    {
        try {
            if (isset($this->repository->resolved[$id])) {
                return $this->repository->resolved[$id]['instance'] ?? $this->repository->resolved[$id];
            }

            if (isset($this->repository->functionReference[$id])) {
                return (new $this->resolver($this->repository))->resolveByDefinition($id);
            }

            $this->repository->resolved[$id] = $this->call($id);

            return $this->repository->resolved[$id]['instance'] ?? $this->repository->resolved[$id];
        } catch (Exception $exception) {
            throw $this->wrapException($exception, $id);
        }
    }

    /**
     * Resolves a class/closure by its name or instance.
     *
     * Will check if the given class/closure is a registered definition.
     * If so, it will use the {@see DefinitionResolver} to resolve it.
     * If not, it will use the {@see Reflector} to try to resolve it.
     *
     * @param  string|Closure|callable  $classOrClosure  The class/closure to be resolved.
     * @param  string|bool|null  $method  The method to be called if classOrClosure is a class.
     * @return mixed The resolved class/closure.
     *
     * @throws ContainerException If the class/closure cannot be resolved.
     */
    public function call(string|Closure|callable $classOrClosure, string|bool|null $method = null): mixed
    {
        $resolver = new $this->resolver($this->repository);

        return match (true) {
            is_string($classOrClosure) && isset($this->repository->functionReference[$classOrClosure]) => $resolver->resolveByDefinition($classOrClosure),

            $classOrClosure instanceof Closure || is_callable($classOrClosure) => $resolver->closureSettler($classOrClosure),

            isset($this->repository->closureResource[$classOrClosure]['on']) => $resolver->closureSettler(
                $this->repository->closureResource[$classOrClosure]['on'],
                $this->repository->closureResource[$classOrClosure]['params']
            ),

            is_string($classOrClosure) => $resolver->classSettler($classOrClosure, $method),

            default => throw new ContainerException('Invalid class/closure format'),
        };
    }

    /**
     * Creates and returns an instance of the specified class.
     *
     * @param  string  $class  The class name to instantiate.
     * @param  string|bool  $method  The method to be called on the class instance or false for none.
     * @return mixed The created instance or the return value of the method.
     */
    public function make(string $class, string|bool $method = false): mixed
    {
        $resolved = (new $this->resolver($this->repository))->classSettler($class, $method, true);

        return $method ? $resolved['returned'] : $resolved['instance'];
    }

    /**
     * Checks if a definition or resolved instance exists for the given identifier.
     *
     * @param  string  $id  The identifier of the definition or instance.
     * @return bool True if the definition or instance exists, false otherwise.
     */
    public function has(string $id): bool
    {
        try {
            return isset($this->repository->functionReference[$id]) ||
                isset($this->repository->resolved[$id]) ||
                $this->get($id);
        } catch (Exception) {
            return false;
        }
    }

    /**
     * Registers a closure with the given parameters to be injected.
     *
     * @param  string  $closureAlias  The alias for the closure.
     * @param  callable|Closure  $function  The closure to be registered.
     * @param  array  $parameters  The parameters to be passed to the closure.
     * @return $this
     *
     * @throws ContainerException
     */
    public function registerClosure(string $closureAlias, callable|Closure $function, array $parameters = []): self
    {
        $this->repository->checkIfLocked();
        $this->repository->closureResource[$closureAlias] = ['on' => $function, 'params' => $parameters];

        return $this;
    }

    /**
     * Registers a class with the given parameters to be injected into the constructor.
     *
     * @param  string  $class  The class name.
     * @param  array  $parameters  The parameters to be passed to the constructor.
     * @return $this
     *
     * @throws ContainerException
     */
    public function registerClass(string $class, array $parameters = []): self
    {
        $this->repository->checkIfLocked();
        $this->repository->classResource[$class]['constructor'] = ['on' => '__constructor', 'params' => $parameters];

        return $this;
    }

    /**
     * Registers a method for a given class.
     *
     * @param  string  $class  The class name.
     * @param  string  $method  The method name.
     * @param  array  $parameters  The parameters to be passed to the method.
     * @return $this
     *
     * @throws ContainerException
     */
    public function registerMethod(string $class, string $method, array $parameters = []): self
    {
        $this->repository->checkIfLocked();
        $this->repository->classResource[$class]['method'] = ['on' => $method, 'params' => $parameters];

        return $this;
    }

    /**
     * Register a property for a given class.
     *
     * @return $this
     *
     * @throws ContainerException
     */
    public function registerProperty(string $class, array $property): self
    {
        $this->repository->checkIfLocked();
        $this->repository->classResource[$class]['property'] = array_merge(
            $this->repository->classResource[$class]['property'] ?? [],
            $property
        );

        return $this;
    }

    /**
     * Set the options for the dependency injection.
     *
     * @param  bool  $injection  Enable or disable dependency injection.
     * @param  bool  $methodAttributes  Enable or disable the ability to inject method parameters.
     * @param  bool  $propertyAttributes  Enable or disable the ability to inject property values.
     * @param  string|null  $defaultMethod  The default method to call when no method is provided.
     * @return $this
     *
     * @throws ContainerException
     */
    public function setOptions(
        bool $injection = true,
        bool $methodAttributes = false,
        bool $propertyAttributes = false,
        ?string $defaultMethod = null
    ): self {
        $this->repository->checkIfLocked();
        $this->repository->defaultMethod = $defaultMethod;
        $this->repository->enablePropertyAttribute = $propertyAttributes;
        $this->repository->enableMethodAttribute = $methodAttributes;
        $this->resolver = $injection ? InjectedCall::class : GenericCall::class;

        return $this;
    }

    /**
     * @param  string|array|Closure|callable  $classAndMethod
     *                                                         - Namespaced class string
     *                                                         - Namespaced class string with method (namespacedClass@method/namespacedClass::method)
     *                                                         - Array with class and method (['namespacedClass', 'method'])
     *                                                         - Closure
     *                                                         - Callable
     * @return array [class, method]
     *
     * @throws InvalidArgumentException
     * @throws ContainerException
     */
    public function split(string|array|Closure|callable $classAndMethod): array
    {
        if (empty($classAndMethod)) {
            throw new InvalidArgumentException('No argument provided!');
        }

        $isString = is_string($classAndMethod);

        $callableFormation = match (true) {
            $classAndMethod instanceof Closure || ($isString && (class_exists($classAndMethod) || is_callable($classAndMethod))) => [$classAndMethod, null],
            is_array($classAndMethod) && class_exists($classAndMethod[0]) => $classAndMethod + [null, null],
            default => null
        };

        return $callableFormation ?: match (true) {
            str_contains($classAndMethod, '@') => explode('@', $classAndMethod, 2),

            str_contains($classAndMethod, '::') => explode('::', $classAndMethod, 2),

            default => throw new ContainerException(
                'Unknown Class & Method formation
                ([namespaced Class, method]/namespacedClass@method/namespacedClass::method)'
            )
        };
    }

    /**
     * Wraps exceptions with a more meaningful error message
     */
    private function wrapException(Exception $exception, string $id): Exception
    {
        return match (true) {
            $exception instanceof NotFoundException => new NotFoundException("No entry found for '$id'"),
            default => new ContainerException("Error retrieving entry '$id': ".$exception->getMessage()),
        };
    }
}
