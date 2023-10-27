<?php

namespace AbmmHasan\InterMix\DI;

use AbmmHasan\InterMix\DI\Invoker\GenericCall;
use AbmmHasan\InterMix\DI\Invoker\InjectedCall;
use AbmmHasan\InterMix\DI\Resolver\Repository;
use AbmmHasan\InterMix\Exceptions\ContainerException;
use AbmmHasan\InterMix\Exceptions\NotFoundException;
use Closure;
use Exception;
use InvalidArgumentException;
use Psr\Cache\InvalidArgumentException as InvalidArgumentExceptionInCache;
use Psr\Container\ContainerInterface;
use ReflectionException;
use Symfony\Contracts\Cache\CacheInterface;

/**
 * Dependency Injector
 */
class Container implements ContainerInterface
{
    protected static array $instances;
    protected Repository $repository;
    protected string $resolver = InjectedCall::class;

    /**
     * Constructs a new instance of the class.
     *
     * @param string $instanceAlias The alias of the instance (default: 'default').
     * @return void
     */
    public function __construct(private string $instanceAlias = 'default')
    {
        self::$instances[$this->instanceAlias] ??= $this;
        $this->repository = new Repository();
        $this->repository->functionReference = [
            ContainerInterface::class => $this
        ];
        $this->repository->alias = $this->instanceAlias;
    }

    /**
     * Creates a new/Get existing instance of the Container class.
     *
     * @param string $instanceAlias The alias for the instance (optional, default: 'default').
     * @return Container The existing or newly created instance of the Container class.
     * @throws InvalidArgumentException If the instance alias is not a string.
     */
    public static function instance(string $instanceAlias = 'default'): Container
    {
        return self::$instances[$instanceAlias] ??= new self($instanceAlias);
    }

    /**
     * Unset current instance
     *
     * @return void
     */
    public function unset(): void
    {
        unset(self::$instances[$this->instanceAlias]);
    }

    /**
     * Lock the container to prevent any further modification
     *
     * @return Container
     */
    public function lock(): Container
    {
        $this->repository->isLocked = true;
        return self::$instances[$this->instanceAlias];
    }

    /**
     * Adds definitions to the container.
     *
     * @param array $definitions The array of definitions to be added [alias/identifier => definition].
     * @return Container The container instance.
     * @throws ContainerException
     */
    public function addDefinitions(array $definitions): Container
    {
        if ($definitions !== []) {
            foreach ($definitions as $identifier => $definition) {
                $this->bind($identifier, $definition);
            }
        }
        return self::$instances[$this->instanceAlias];
    }

    /**
     * Binds a definition to an identifier in the container.
     *
     * @param string $id The identifier to bind the definition to.
     * @param mixed $definition The definition to bind to the identifier.
     * @return Container The container instance.
     * @throws ContainerException If the identifier and definition are the same.
     */
    public function bind(string $id, mixed $definition): Container
    {
        $this->repository->checkIfLocked();
        if ($id === $definition) {
            throw new ContainerException("Id & definition cannot be same ($id)");
        }
        $this->repository->functionReference[$id] = $definition;
        return self::$instances[$this->instanceAlias];
    }

    /**
     * Caches all definitions and returns the instance alias.
     *
     * @param bool $forceClearFirst Whether to clear the cache before caching the definitions.
     * @return mixed The instance alias.
     * @throws ContainerException|InvalidArgumentExceptionInCache
     */
    public function cacheAllDefinitions(bool $forceClearFirst = false): Container
    {
        if (empty($this->repository->functionReference)) {
            throw new ContainerException('No definitions added.');
        }

        if (!isset($this->repository->cacheAdapter)) {
            throw new ContainerException('No cache adapter set.');
        }

        if ($forceClearFirst) {
            $this->repository->cacheAdapter->clear($this->repository->alias . '-');
        }

        $resolver = new $this->resolver($this->repository);
        foreach ($this->repository->functionReference as $id => $definition) {
            $this->repository->resolvedDefinition[$id] = $this->repository->cacheAdapter->get(
                base64_encode($this->repository->alias . '-' . $id),
                fn () => $resolver->resolveByDefinition($id)
            );
        }

        return self::$instances[$this->instanceAlias];
    }

    /**
     * Enable definition cache.
     *
     * @param CacheInterface $cache The cache instance to use.
     * @return Container The container instance.
     */
    public function enableDefinitionCache(CacheInterface $cache): Container
    {
        $this->repository->cacheAdapter = $cache;
        return self::$instances[$this->instanceAlias];
    }

    /**
     * Retrieves the return value of the function based on the provided ID.
     *
     * @param string $id The ID of the value to retrieve.
     * @return mixed The resolved value or the returned value from the repository.
     * @throws ContainerException|NotFoundException|InvalidArgumentExceptionInCache
     */
    public function getReturn(string $id): mixed
    {
        try {
            $resolved = $this->get($id);
            if (array_key_exists($id, $this->repository->functionReference)) {
                return $resolved;
            }
            return $this->repository->resolved[$id]['returned'] ?? $resolved;
        } catch (NotFoundException $exception) {
            throw new NotFoundException($exception->getMessage());
        } catch (ContainerException $exception) {
            throw new ContainerException($exception->getMessage());
        }
    }

    /**
     * Retrieves a value from the repository based on the given ID.
     *
     * @param string $id The ID of the value to retrieve.
     * @return mixed The retrieved value.
     * @throws ContainerException|NotFoundException|InvalidArgumentExceptionInCache
     */
    public function get(string $id): mixed
    {
        try {
            $existsInResolved = array_key_exists($id, $this->repository->resolved);
            if ($existsInDefinition = array_key_exists($id, $this->repository->functionReference)) {
                return (new $this->resolver($this->repository))
                    ->resolveByDefinition($id);
            }

            if (!$existsInResolved) {
                $this->repository->resolved[$id] = $this->call($id);
            }

            return $this->repository->resolved[$id]['instance'] ?? $this->repository->resolved[$id];
        } catch (ReflectionException|ContainerException|InvalidArgumentExceptionInCache $exception) {
            throw new ContainerException("Error while retrieving the entry: " . $exception->getMessage());
        } catch (Exception $exception) {
            if (!$existsInDefinition || !$existsInResolved) {
                throw new NotFoundException("No entry found for '$id' identifier");
            }
            throw new ContainerException("Error while retrieving the entry: " . $exception->getMessage());
        }
    }

    /**
     * Calls the provided class or closure based on the conditions.
     *
     * @param string|Closure|callable $classOrClosure The class, closure, or callable to be called.
     * @param string|bool $method The method to be called (optional).
     * @return mixed The result of the function call.
     * @throws ContainerException|InvalidArgumentExceptionInCache
     */
    public function call(
        string|Closure|callable $classOrClosure,
        string|bool $method = null
    ): mixed {
        $callableIsString = is_string($classOrClosure);

        return match (true) {
            $callableIsString && array_key_exists(
                $classOrClosure,
                $this->repository->functionReference
            ) => (new $this->resolver($this->repository))
                ->resolveByDefinition($classOrClosure),

            $classOrClosure instanceof Closure || (is_callable($classOrClosure) && !is_array($classOrClosure))
            => (new $this->resolver($this->repository))
                ->closureSettler($classOrClosure),

            !$callableIsString => throw new ContainerException('Invalid class/closure format'),

            !empty($this->repository->closureResource[$classOrClosure]['on']) =>
            (new $this->resolver($this->repository))->closureSettler(
                $this->repository->closureResource[$classOrClosure]['on'],
                $this->repository->closureResource[$classOrClosure]['params']
            ),

            default => (new $this->resolver($this->repository))->classSettler($classOrClosure, $method)
        };
    }

    /**
     * Generates a new uncached instance of the specified class and executes a method (if specified).
     *
     * @param string $class The name of the class to instantiate.
     * @param string|bool $method The name of the method to execute on the class instance. Defaults to false.
     * @return mixed The result of the method execution.
     */
    public function make(string $class, string|bool $method = false): mixed
    {
        return (new $this->resolver($this->repository))->classSettler($class, $method, true);
    }

    /**
     * Checks if the given ID exists in the function reference or the resolved repository.
     *
     * @param string $id The ID to check.
     * @return bool Returns true if the ID exists, false otherwise.
     */
    public function has(string $id): bool
    {
        try {
            if (
                array_key_exists($id, $this->repository->functionReference) ||
                array_key_exists($id, $this->repository->resolved)
            ) {
                return true;
            }
            $this->get($id);
            return array_key_exists($id, $this->repository->resolved);
        } catch (NotFoundException|ContainerException|InvalidArgumentExceptionInCache) {
            return false;
        }
    }

    /**
     * Registers a closure in the container.
     *
     * @param string $closureAlias The alias for the closure.
     * @param callable|Closure $function The closure or callable to register.
     * @param array $parameters The parameters to pass to the closure or callable.
     * @return Container The container instance.
     * @throws ContainerException
     */
    public function registerClosure(string $closureAlias, callable|Closure $function, array $parameters = []): Container
    {
        $this->repository
            ->checkIfLocked()
            ->closureResource[$closureAlias] = [
            'on' => $function,
            'params' => $parameters
        ];
        return self::$instances[$this->instanceAlias];
    }

    /**
     * Registers a class in the container with optional parameters.
     *
     * @param string $class The name of the class to register.
     * @param array $parameters An array of parameters for the class constructor. Default is an empty array.
     * @return Container The current instance of the container.
     * @throws ContainerException If the container is locked and cannot be modified.
     */
    public function registerClass(string $class, array $parameters = []): Container
    {
        $this->repository
            ->checkIfLocked()
            ->classResource[$class]['constructor'] = [
            'on' => '__constructor',
            'params' => $parameters
        ];
        return self::$instances[$this->instanceAlias];
    }

    /**
     * Registers a method in the container.
     *
     * @param string $class The class name.
     * @param string $method The method name.
     * @param array $parameters (optional) The method parameters. Default is an empty array.
     * @return Container The container instance.
     * @throws ContainerException
     */
    public function registerMethod(string $class, string $method, array $parameters = []): Container
    {
        $this->repository
            ->checkIfLocked()
            ->classResource[$class]['method'] = [
            'on' => $method,
            'params' => $parameters
        ];
        return self::$instances[$this->instanceAlias];
    }

    /**
     * Registers a property for a given class in the container.
     *
     * @param string $class The name of the class.
     * @param array $property An array containing the properties to register.
     * @return Container The container instance.
     * @throws ContainerException
     */
    public function registerProperty(string $class, array $property): Container
    {
        $this->repository
            ->checkIfLocked()
            ->classResource[$class]['property'] = array_merge(
                $this->repository->classResource[$class]['property'] ?? [],
                $property
            );
        return self::$instances[$this->instanceAlias];
    }

    /**
     * Sets the options for the Container.
     *
     * @param bool $injection Whether to enable injection or not. Default is true.
     * @param bool $methodAttributes Whether to enable method attributes or not. Default is false.
     * @param bool $propertyAttributes Whether to enable property attributes or not. Default is false.
     * @param string|null $defaultMethod The default method to use. Null if not specified.
     * @return Container The Container instance.
     * @throws ContainerException
     */
    public function setOptions(
        bool $injection = true,
        bool $methodAttributes = false,
        bool $propertyAttributes = false,
        string $defaultMethod = null
    ): Container {
        $this->repository->checkIfLocked();
        $this->repository->defaultMethod = $defaultMethod ?: null;
        $this->repository->enablePropertyAttribute = $propertyAttributes;
        $this->repository->enableMethodAttribute = $methodAttributes;
        $this->resolver = $injection ? InjectedCall::class : GenericCall::class;

        return self::$instances[$this->instanceAlias];
    }

    /**
     * Splits a string or array representing a class and method into an array.
     *
     * @param string|array|Closure|callable $classAndMethod The string or array representing the class and method.
     * @return array The array representing the split class and method.
     * @throws ContainerException If the class and method formation is unknown.
     * @throws InvalidArgumentException If no argument is found.
     */
    public function split(string|array|Closure|callable $classAndMethod): array
    {
        if (empty($classAndMethod)) {
            throw new InvalidArgumentException(
                'No argument found!'
            );
        }

        $isString = is_string($classAndMethod);

        $callableFormation = match (true) {
            $classAndMethod instanceof Closure ||
            ($isString && (class_exists($classAndMethod) || is_callable($classAndMethod)))
            => [$classAndMethod, null],

            is_array($classAndMethod) && class_exists($classAndMethod[0]) => $classAndMethod + [null, null],

            default => null
        };

        if (!$isString) {
            throw new ContainerException(
                'Unknown Class & Method formation
                ([namespaced Class, method]/namespacedClass@method/namespacedClass::method)'
            );
        }

        return $callableFormation ?: match (true) {
            str_contains($classAndMethod, '@')
            => explode('@', $classAndMethod, 2),

            str_contains($classAndMethod, '::')
            => explode('::', $classAndMethod, 2),

            default => throw new ContainerException(
                'Unknown Class & Method formation
                ([namespaced Class, method]/namespacedClass@method/namespacedClass::method)'
            )
        };
    }
}
