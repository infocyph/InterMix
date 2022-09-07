<?php


namespace AbmmHasan\OOF\DI;

use Closure;
use Exception;
use ReflectionException;

/**
 * Dependency Injector
 */
final class Container
{
    public array $functionReference = [];
    public array $classResource = [];
    public bool $allowPrivateMethodAccess = false;
    private array $closureResource = [];
    public string $resolveParameters = 'resolveAssociativeParameters';
    public ?string $defaultMethod = null;
    public array $resolvedResource = [];
    public bool $forceSingleton = false;
    private static array $instances;
    private string $resolver = 'DependencyResolver';

    /**
     * Class Constructor
     */
    public function __construct(private string $instanceAlias = 'oof')
    {
        self::$instances[$this->instanceAlias] ??= $this;
    }

    /**
     * Get Container instance
     *
     * @param string $instanceAlias
     * @return Container
     */
    public static function instance(string $instanceAlias = 'oof'): Container
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
     * Register Closure
     *
     * @param string $closureAlias
     * @param callable|Closure $function
     * @param array $parameters
     * @return Container
     */
    public function registerClosure(string $closureAlias, callable|Closure $function, array $parameters = []): Container
    {
        $this->closureResource[$closureAlias] = [
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
        $this->classResource[$class]['constructor'] = [
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
        $this->classResource[$class]['method'] = [
            'on' => $method,
            'params' => $parameters
        ];
        return self::$instances[$this->instanceAlias];
    }

    /**
     * Set resource for parameter to Class Constructor/Method resolver
     *
     * @param array $parameterResource
     * @return Container
     */
    public function registerParamToClass(array $parameterResource): Container
    {
        $this->functionReference['common'] = $parameterResource;
        return self::$instances[$this->instanceAlias];
    }

    /**
     * Set resource for parameter to Class Constructor resolver
     *
     * @param array $parameterResource
     * @return Container
     */
    public function registerParamToConstructor(array $parameterResource): Container
    {
        $this->functionReference['constructor'] = $parameterResource;
        return self::$instances[$this->instanceAlias];
    }

    /**
     * Set resource for parameter to Class Method resolver
     *
     * @param array $parameterResource
     * @return Container
     */
    public function registerParamToMethod(array $parameterResource): Container
    {
        $this->functionReference['method'] = $parameterResource;
        return self::$instances[$this->instanceAlias];
    }

    /**
     * Allow access to private methods
     *
     * @return Container
     */
    public function allowPrivateMethodAccess(): Container
    {
        $this->allowPrivateMethodAccess = true;
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
        $this->defaultMethod = $method;
        return self::$instances[$this->instanceAlias];
    }

    /**
     * Disable resolution by name (instead it will resolve in sequence)
     *
     * @return Container
     */
    public function disableNamedParameter(): Container
    {
        $this->resolveParameters = 'resolveNonAssociativeParameters';
        return self::$instances[$this->instanceAlias];
    }

    /**
     * Enable Singleton (instead of resolving same class multiple time, return same instance for each)
     *
     * @return Container
     */
    public function enableSingleton(): Container
    {
        $this->forceSingleton = true;
        return self::$instances[$this->instanceAlias];
    }

    /**
     * Disable auto resolution
     *
     * @return Container
     */
    public function disableAutoResolution(): Container
    {
        $this->resolver = 'GenericResolver';
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
        } elseif (!empty($this->closureResource[$closureAlias]['on'])) {
            $closure = $this->closureResource[$closureAlias]['on'];
            $params = $this->closureResource[$closureAlias]['params'];
        } else {
            throw new Exception('Closure not registered!');
        }
        return (new $this->resolver($this))->closureSettler($closure, $params);
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
        return (new $this->resolver($this))->classSettler($class, $method)['returned'];
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
        return (new $this->resolver($this))->classSettler($class)['instance'];
    }
}
