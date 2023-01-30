<?php


namespace AbmmHasan\OOF\DI;

use AbmmHasan\OOF\DI\Resolver\DependencyResolver;
use AbmmHasan\OOF\DI\Resolver\GenericResolver;
use AbmmHasan\OOF\Exceptions\ContainerException;
use AbmmHasan\OOF\Exceptions\NotFoundException;
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
    protected Asset $assets;
    protected string $resolver = DependencyResolver::class;

    /**
     * Class Constructor
     */
    public function __construct(private string $instanceAlias = 'default')
    {
        self::$instances[$this->instanceAlias] ??= $this;
        $this->assets = new Asset();
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
        $this->assets->functionReference[$id] = $definition;
        return self::$instances[$this->instanceAlias];
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
            $existsInResolved = array_key_exists($id, $this->assets->resolved);
            if ($existsInDefinition = array_key_exists($id, $this->assets->functionReference)) {
                return (new $this->resolver($this->assets))
                    ->resolveByDefinition($this->assets->functionReference[$id], $id);
            }

            if ($existsInResolved) {
                return $this->assets->resolved[$id];
            }

            return $this->assets->resolved[$id] = $this->call($id);
        } catch (Exception|ReflectionException $exception) {
            if (!$existsInDefinition || !$existsInResolved) {
                throw new NotFoundException("No entry found for '$id' identifier");
            }
            throw new ContainerException("Error while retrieving the entry: " . $exception->getMessage());
        }
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
                array_key_exists($id, $this->assets->functionReference) ||
                array_key_exists($id, $this->assets->resolved)
            ) {
                return true;
            }
            $this->get($id);
            return array_key_exists($id, $this->assets->resolved);
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
        $this->assets
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
        $this->assets
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
        $this->assets
            ->classResource[$class]['method'] = [
            'on' => $method,
            'params' => $parameters
        ];
        return self::$instances[$this->instanceAlias];
    }

    /**
     * Set resolution options
     *
     * @param bool $enableSingleton Enable Singleton (instead of resolving same class multiple time, return same instance for each)
     * @param string|null $defaultMethod Set default call method (will be called if no method/callOn const provided)
     * @param bool $autoWiring Enable/Disable auto-wiring/auto-resolution
     * @param bool $allowPrivateMethodAccess Allow access to private methods (discouraged)
     * @return Container
     */
    public function setOptions(
        bool $enableSingleton = false,
        string $defaultMethod = null,
        bool $autoWiring = true,
        bool $allowPrivateMethodAccess = false
    ): Container {
        $this->assets->forceSingleton = $enableSingleton;
        $this->assets->defaultMethod = $defaultMethod ?: null;
        $this->assets->allowPrivateMethodAccess = $allowPrivateMethodAccess;
        $this->resolver = $autoWiring ? DependencyResolver::class : GenericResolver::class;

        return self::$instances[$this->instanceAlias];
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

        if ($callableIsString && array_key_exists($classOrClosure, $this->assets->functionReference)) {
            return (new $this->resolver($this->assets))
                ->resolveByDefinition($this->assets->functionReference[$classOrClosure], $classOrClosure);
        }

        if ($classOrClosure instanceof Closure || (is_callable($classOrClosure) && !is_array($classOrClosure))) {
            return (new $this->resolver($this->assets))
                ->closureSettler($classOrClosure);
        }

        if (!$callableIsString) {
            throw new ContainerException('Invalid class/closure format');
        }

        if (!empty($this->assets->closureResource[$classOrClosure]['on'])) {
            return (new $this->resolver($this->assets))
                ->closureSettler(
                    $this->assets->closureResource[$classOrClosure]['on'],
                    $this->assets->closureResource[$classOrClosure]['params']
                );
        }

        return (new $this->resolver($this->assets))->classSettler($classOrClosure, $method);
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
        if ($classAndMethod instanceof Closure || (is_callable($classAndMethod) && !is_array($classAndMethod))) {
            return [$classAndMethod];
        }

        if (is_string($classAndMethod)) {
            if (str_contains($classAndMethod, '@')) {
                return explode('@', $classAndMethod, 2);
            }

            if (str_contains($classAndMethod, '::')) {
                return explode('::', $classAndMethod, 2);
            }
        }

        if (is_array($classAndMethod) && count($classAndMethod) === 2) {
            return $classAndMethod;
        }

        throw new ContainerException(
            'Unknown Class & Method formation (either [namspaced Class, method] or namspacedClass@method or namespacedClass::method)'
        );
    }
}
