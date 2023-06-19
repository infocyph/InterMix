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
use Psr\Container\ContainerInterface;
use ReflectionException;

/**
 * Dependency Injector
 */
class Container implements ContainerInterface
{
    protected static array $instances;
    protected Repository $repository;
    protected string $resolver = InjectedCall::class;

    /**
     * Class Constructor
     */
    public function __construct(private string $instanceAlias = 'default')
    {
        self::$instances[$this->instanceAlias] ??= $this;
        $this->repository = new Repository();
        $this->repository->functionReference = [
            ContainerInterface::class => $this
        ];
    }

    /**
     * Get Container instance
     *
     * @param string $instanceAlias Instance alias
     * @return Container
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
     * Add definitions
     *
     * @param array $definitions [alias/identifier => definition]
     * @return Container
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
     * Add definition
     *
     * @param string $id Identifier/alias of the entry
     * @param mixed $definition Entry definition
     * @return Container
     * @throws ContainerException
     */
    public function bind(string $id, mixed $definition): Container
    {
        $this->repository->checkIfLocked();
        if ($id === $definition) {
            throw new ContainerException("Circular dependency detected ($id)");
        }
        $this->repository->functionReference[$id] = $definition;
        return self::$instances[$this->instanceAlias];
    }

    /**
     * Finds an entry of the container (returned result) by its identifier and returns it.
     *
     * @param string $id Identifier of the entry
     * @return mixed
     * @throws ContainerException|NotFoundException
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
     * Finds an entry of the container by its identifier and returns it.
     *
     * @param string $id Identifier of the entry
     * @return mixed
     * @throws NotFoundException|ContainerException
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
        } catch (Exception|ReflectionException|ContainerException $exception) {
            $containerException = $exception instanceof ContainerException ||
                $exception instanceof ReflectionException;
            if (!$containerException && (!$existsInDefinition || !$existsInResolved)) {
                throw new NotFoundException("No entry found for '$id' identifier");
            }
            throw new ContainerException("Error while retrieving the entry: " . $exception->getMessage());
        }
    }

    /**
     * Get the resolved class/closure/class-method
     *
     * @param string|Closure|callable $classOrClosure class name with namespace / closure
     * @param string|bool|null $method method within the class (if class)
     * @return mixed
     * @throws ContainerException
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

            $classOrClosure instanceof Closure || (is_callable($classOrClosure) && !is_array(
                    $classOrClosure
                )) => (new $this->resolver($this->repository))
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
     * Get new (uncached) instance of the Class
     *
     * @param string $class class name with namespace
     * @param string|bool $method method within the class
     * @return mixed
     */
    public function make(string $class, string|bool $method = false): mixed
    {
        return (new $this->resolver($this->repository))->classSettler($class, $method, true);
    }

    /**
     * Returns true if the container can return an entry for the given identifier.
     *
     * @param string $id Identifier of the entry to look for
     * @return bool
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
        } catch (NotFoundException|ContainerException) {
            return false;
        }
    }

    /**
     * Register Closure
     *
     * @param string $closureAlias Closure alias
     * @param callable|Closure $function the Closure
     * @param array $parameters Closure parameters
     * @return Container
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
     * Register Class with constructor Parameter
     *
     * @param string $class class name with namespace
     * @param array $parameters constructor parameters
     * @return Container
     * @throws ContainerException
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
     * Register Class and Method with Parameter (method parameter)
     *
     * @param string $class class name with namespace
     * @param string $method method within the class
     * @param array $parameters parameters to provide within method
     * @return Container
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
     * Register Class and Method with Parameter (method parameter)
     *
     * @param string $class class name with namespace
     * @param array $property ['property name' => 'value to assign']
     * @return Container
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
     * Set resolution options
     *
     * @param bool $injection Enable/Disable dependency injection
     * @param bool $methodAttributes Enable/Disable dependency injection based on method attributes
     * @param bool $propertyAttributes Enable/Disable dependency injection based on property attributes
     * @param string|null $defaultMethod Set default call method (will be called if no method/callOn const provided)
     * @return Container
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
     * Get parsed class & method information from string
     *
     * @param string|array|Closure|callable $classAndMethod formatted class name (with method) / closure
     * @return array
     * @throws ContainerException
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
                ([namspaced Class, method]/namspacedClass@method/namespacedClass::method)'
            );
        }

        return $callableFormation ?: match (true) {
            str_contains($classAndMethod, '@')
            => explode('@', $classAndMethod, 2),

            str_contains($classAndMethod, '::')
            => explode('::', $classAndMethod, 2),

            default => throw new ContainerException(
                'Unknown Class & Method formation
                ([namspaced Class, method]/namspacedClass@method/namespacedClass::method)'
            )
        };
    }
}
