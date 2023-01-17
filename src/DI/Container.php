<?php


namespace AbmmHasan\OOF\DI;

use AbmmHasan\OOF\DI\Resolver\DependencyResolver;
use AbmmHasan\OOF\DI\Resolver\GenericResolver;
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
     */
    public function set(string $id, mixed $definition): Container
    {
        $this->assets->functionReference[$id] = $definition;
        return self::$instances[$this->instanceAlias];
    }

    /**
     * Finds an entry of the container by its identifier and returns it.
     *
     * @param string $id Identifier of the entry
     * @return mixed
     * @throws NotFoundException
     */
    public function get(string $id): mixed
    {
        if (!$this->has($id)) {
            throw new NotFoundException("No entry found for '$id' identifier");
        }
        // TODO: Implement get() method.
    }

    /**
     * Returns true if the container can return an entry for the given identifier.
     *
     * @param string $id Identifier of the entry to look for
     * @return bool
     */
    public function has(string $id): bool
    {
        return array_key_exists($id, $this->assets->functionReference);
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
     * Allow access to private methods
     *
     * @return Container
     */
    public function allowPrivateMethodAccess(): Container
    {
        $this->assets
            ->allowPrivateMethodAccess = true;
        return self::$instances[$this->instanceAlias];
    }

    /**
     * Set default call method (will be called if no method/callOn const provided)
     *
     * @param string $method existing method name for class
     * @return Container
     */
    public function setDefaultMethod(string $method): Container
    {
        $this->assets->defaultMethod = $method;
        return self::$instances[$this->instanceAlias];
    }

    /**
     * Enable Singleton (instead of resolving same class multiple time, return same instance for each)
     *
     * @return Container
     */
    public function enableSingleton(): Container
    {
        $this->assets
            ->forceSingleton = true;
        return self::$instances[$this->instanceAlias];
    }

    /**
     * Disable auto resolution
     *
     * @return Container
     */
    public function disableAutoResolution(): Container
    {
        $this->resolver = GenericResolver::class;
        return self::$instances[$this->instanceAlias];
    }

    /**
     * Call the desired closure
     *
     * @param string|Closure|callable $closureAlias
     * @return mixed
     * @throws Exception
     */
    public function callClosure(string|Closure|callable $closureAlias): mixed
    {
        if ($closureAlias instanceof Closure || is_callable($closureAlias)) {
            $closure = $closureAlias;
            $params = [];
        } elseif (!empty($this->assets->closureResource[$closureAlias]['on'])) {
            $closure = $this->assets->closureResource[$closureAlias]['on'];
            $params = $this->assets->closureResource[$closureAlias]['params'];
        } else {
            throw new Exception('Closure not registered!');
        }
        return (new $this->resolver($this->assets))->closureSettler($closure, $params);
    }

    /**
     * Call the desired class (along with the method)
     *
     * @param string $class
     * @param string|null $method
     * @return mixed
     * @throws ReflectionException
     */
    public function callMethod(string $class, string $method = null): mixed
    {
        return (new $this->resolver($this->assets))->classSettler($class, $method)['returned'];
    }

    /**
     * Get Class Instance
     *
     * @param string $class
     * @return mixed
     * @throws ReflectionException
     */
    public function getInstance(string $class): mixed
    {
        return (new $this->resolver($this->assets))->classSettler($class, false)['instance'];
    }

    /**
     * Get parsed class & method information from string
     *
     * @param string|array $classAndMethod
     * @return array
     * @throws Exception
     */
    public function split(string|array $classAndMethod): array
    {
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

        throw new Exception(
            'Unknown Class & Method formation (either [namspaced Class, method] or namspacedClass@method or namespacedClass::method)'
        );
    }
}
