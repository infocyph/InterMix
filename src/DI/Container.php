<?php


namespace AbmmHasan\InterMix\DI;

use AbmmHasan\InterMix\DI\Invoker\GenericCall;
use AbmmHasan\InterMix\DI\Invoker\InjectedCall;
use AbmmHasan\InterMix\DI\Resolver\Repository;
use AbmmHasan\InterMix\Exceptions\ContainerException;
use AbmmHasan\InterMix\Exceptions\NotFoundException;
use Closure;
use Exception;
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
    }

    /**
     * Get Container instance
     *
     * @param string $instanceAlias
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
                $this->set($identifier, $definition);
            }
        }
        return self::$instances[$this->instanceAlias];
    }

    /**
     * Add definition
     *
     * @param string $id Identifier of the entry
     * @param mixed $definition
     * @return Container
     * @throws ContainerException
     */
    public function set(string $id, mixed $definition): Container
    {
        if ($id === $definition) {
            throw new ContainerException("Circular dependency detected ($id)");
        }
        $this->repository->functionReference[$id] = $definition;
        return self::$instances[$this->instanceAlias];
    }

    /**
     * Finds an entry of the container (returned result) by its identifier and returns it.
     *
     * @param string $id
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
                    ->resolveByDefinition($this->repository->functionReference[$id], $id);
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
     * @param string|Closure|callable $classOrClosure
     * @param string|bool|null $method
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
                ->resolveByDefinition($this->repository->functionReference[$classOrClosure], $classOrClosure),

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
        } catch (NotFoundException|ContainerException $exception) {
            return false;
        }
    }

    /**
     * Register Closure
     *
     * @param string $closureAlias
     * @param callable|Closure $function
     * @param array $parameters
     * @return Container
     */
    public function registerClosure(string $closureAlias, callable|Closure $function, array $parameters = []): Container
    {
        $this->repository
            ->closureResource[$closureAlias] = [
            'on' => $function,
            'params' => $parameters
        ];
        return self::$instances[$this->instanceAlias];
    }

    /**
     * Register Class with constructor Parameter
     *
     * @param string $class
     * @param array $parameters
     * @return Container
     */
    public function registerClass(string $class, array $parameters = []): Container
    {
        $this->repository
            ->classResource[$class]['constructor'] = [
            'on' => '__constructor',
            'params' => $parameters
        ];
        return self::$instances[$this->instanceAlias];
    }

    /**
     * Register Class and Method with Parameter (method parameter)
     *
     * @param string $class
     * @param string $method
     * @param array $parameters
     * @return Container
     */
    public function registerMethod(string $class, string $method, array $parameters = []): Container
    {
        $this->repository
            ->classResource[$class]['method'] = [
            'on' => $method,
            'params' => $parameters
        ];
        return self::$instances[$this->instanceAlias];
    }

    /**
     * Register Class and Method with Parameter (method parameter)
     *
     * @param string $class
     * @param string $property
     * @param mixed $value
     * @return Container
     */
    public function registerProperty(string $class, string $property, mixed $value = null): Container
    {
        $this->repository->classResource[$class]['property'][$property] = $value;
        return self::$instances[$this->instanceAlias];
    }

    /**
     * Set resolution options
     *
     * @param bool $enableInjection Enable/Disable dependency injection
     * @param bool $useAttributes Enable/Disable dependency injection based on attributes
     * @param bool $enablePropertyResolution Enable/Disable dependency injection on properties
     * @param string|null $defaultMethod Set default call method (will be called if no method/callOn const provided)
     * @return Container
     */
    public function setOptions(
        bool $enableInjection = true,
        bool $enablePropertyResolution = true,
        bool $useAttributes = false,
        string $defaultMethod = null
    ): Container {
        $this->repository->defaultMethod = $defaultMethod ?: null;
        $this->repository->enableAttribute = $useAttributes;
        $this->repository->enableProperties = $enablePropertyResolution;
        $this->resolver = $enableInjection ? InjectedCall::class : GenericCall::class;

        return self::$instances[$this->instanceAlias];
    }

    /**
     * Get parsed class & method information from string
     *
     * @param string|array|Closure|callable $classAndMethod
     * @return array
     * @throws ContainerException
     */
    public function split(string|array|Closure|callable $classAndMethod): array
    {
        return match (true) {
            $classAndMethod instanceof Closure || (is_callable($classAndMethod) && !is_array(
                    $classAndMethod
                )) => [$classAndMethod],

            is_array($classAndMethod) && count($classAndMethod) === 2 => $classAndMethod,

            is_string($classAndMethod) && str_contains($classAndMethod, '@')
            => explode('@', $classAndMethod, 2),

            is_string($classAndMethod) && str_contains($classAndMethod, '::')
            => explode('::', $classAndMethod, 2),

            default => throw new ContainerException(
                'Unknown Class & Method formation
                ([namspaced Class, method]/namspacedClass@method/namespacedClass::method)'
            )
        };
    }
}
