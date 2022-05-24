<?php


namespace AbmmHasan\OOF\DI;

use Closure;
use Exception;
use ReflectionClass;
use ReflectionException;
use ReflectionFunction;
use ReflectionFunctionAbstract;
use ReflectionMethod;
use ReflectionNamedType;
use ReflectionParameter;
use stdClass;

/**
 * Dependency Injector
 */
final class Container
{
    private array $functionReference = [];
    private stdClass $stdClass;
    private array $classResource = [];
    private bool $allowPrivateMethodAccess = false;
    private static array $instances;
    private array $closureResource = [];
    private string $resolveParameters = 'resolveAssociativeParameters';
    private ?string $defaultMethod = null;

    /**
     * Class Constructor
     */
    public function __construct(private string $instanceAlias = 'oof')
    {
        self::$instances[$this->instanceAlias] ??= $this;
        $this->stdClass = new stdClass();
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
     * @param Closure $function
     * @param array $parameters
     * @return Container
     */
    public function registerClosure(string $closureAlias, Closure $function, array $parameters = []): Container
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
     * Call the desired closure
     *
     * @param string|Closure $closureAlias
     * @return mixed
     * @throws ReflectionException|Exception
     */
    public function callClosure(string|Closure $closureAlias): mixed
    {
        if ($closureAlias instanceof Closure) {
            $closure = $closureAlias;
            $params = [];
        } elseif (!empty($this->closureResource[$closureAlias]['on'])) {
            $closure = $this->closureResource[$closureAlias]['on'];
            $params = $this->closureResource[$closureAlias]['params'];
        } else {
            throw new Exception('Closure not registered!');
        }
        return $closure(...$this->{$this->resolveParameters}(
            new ReflectionFunction($closure),
            $params,
            'constructor'
        ));

    }

    /**
     * Call the desired class (along with the method)
     *
     * @param string $class
     * @return mixed
     * @throws ReflectionException
     */
    public function callMethod(string $class): mixed
    {
        return $this->getResolvedInstance(new ReflectionClass($class))['returned'];
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
        return $this->getResolvedInstance(new ReflectionClass($class))['instance'];
    }

    /**
     * Get resolved Instance & method
     *
     * @param ReflectionClass $class
     * @param mixed|null $supplied
     * @return array
     * @throws ReflectionException|Exception
     */
    private function getResolvedInstance(ReflectionClass $class, mixed $supplied = null): array
    {
        $className = $class->getName();
        if ($class->isInterface()) {
            if (!class_exists($supplied)) {
                throw new Exception("Resolution failed: $supplied for interface $className");
            }
            [$interface, $className] = [$className, $supplied];
            $class = new ReflectionClass($className);
            if (!$class->implementsInterface($interface)) {
                throw new Exception("$className doesn't implement $interface");
            }
        }
        $instance = $this->getClassInstance(
            $class, $this->classResource[$className]['constructor']['params'] ?? []
        );
        $return = null;
        $method = $this->classResource[$className]['method']['on']
            ?? ($class->getConstant('callOn') ?: $this->defaultMethod);
        if (!empty($method) && $class->hasMethod($method)) {
            $return = $this->invokeMethod(
                $instance,
                $method,
                $this->classResource[$className]['method']['params'] ?? []
            );
        }
        return [
            'instance' => $instance,
            'returned' => $return,
            'reflection' => $class
        ];
    }

    /**
     * Resolve Function parameter
     *
     * @param ReflectionFunctionAbstract $reflector
     * @param array $suppliedParameters
     * @param string $type
     * @return array
     * @throws ReflectionException|Exception
     */
    private function resolveNonAssociativeParameters(
        ReflectionFunctionAbstract $reflector,
        array                      $suppliedParameters,
        string                     $type
    ): array
    {
        $processed = [];
        $instanceCount = $parameterIndex = 0;
        $values = array_values($suppliedParameters);
        $parent = $reflector->class ?? $reflector->getName();
        foreach ($reflector->getParameters() as $key => $classParameter) {
            [$incrementBy, $instance] = $this->resolveDependency(
                $parent,
                $classParameter,
                $processed,
                $type,
                $suppliedParameters[$classParameter->getName()] ?? null
            );
            $processed[] = match (true) {
                $instance !== $this->stdClass
                => [$instance, $instanceCount++, $parameterIndex += $incrementBy][0],

                !isset($values[$key - $instanceCount]) && $classParameter->isDefaultValueAvailable()
                => $classParameter->getDefaultValue(),

                default => $values[$parameterIndex++] ??
                    throw new Exception(
                        "Resolution failed: '{$classParameter->getName()}' of $parent::{$reflector->getShortName()}()!"
                    )
            };
        }
        return $processed;
    }

    /**
     * Resolve Function parameter
     *
     * @param ReflectionFunctionAbstract $reflector
     * @param array $suppliedParameters
     * @param string $type
     * @return array
     * @throws ReflectionException|Exception
     */
    private function resolveAssociativeParameters(
        ReflectionFunctionAbstract $reflector,
        array                      $suppliedParameters,
        string                     $type
    ): array
    {
        $processed = [];
        $instanceCount = 0;
        $values = array_values($suppliedParameters);
        $parent = $reflector->class ?? $reflector->getName();
        foreach ($reflector->getParameters() as $key => $classParameter) {
            [$incrementBy, $instance] = $this->resolveDependency(
                $parent,
                $classParameter,
                $processed,
                $type,
                $suppliedParameters[$classParameter->getName()] ?? null
            );
            $processed[$classParameter->getName()] = match (true) {
                $instance !== $this->stdClass
                => [$instance, $instanceCount++][0],

                !isset($values[$key - $instanceCount]) && $classParameter->isDefaultValueAvailable()
                => $classParameter->getDefaultValue(),

                default => $suppliedParameters[$classParameter->getName()] ??
                    throw new Exception(
                        "Resolution failed: '{$classParameter->getName()}' of $parent::{$reflector->getShortName()}()!"
                    )
            };
        }
        return $processed;
    }

    /**
     * Resolve parameter dependency
     *
     * @param string $callee
     * @param ReflectionParameter $parameter
     * @param array $parameters
     * @param string $type
     * @param string|null $supplied
     * @return array
     * @throws ReflectionException|Exception
     */
    private function resolveDependency(
        string              $callee,
        ReflectionParameter $parameter,
        array               $parameters,
        string              $type,
        mixed               $supplied
    ): array
    {
        if ($class = $this->resolveClass($parameter, $type)) {
            if ($callee === $class->name) {
                throw new Exception("Looped call detected: $callee");
            }
            if (!$this->alreadyExist($class->name, $parameters)) {
                if ($parameter->isDefaultValueAvailable()) {
                    return [0, null];
                }
                $incrementBy = 0;
                if (
                    $supplied !== null &&
                    ($constructor = $class->getConstructor()) !== null &&
                    count($passable = $constructor->getParameters())
                ) {
                    if ($this->resolveParameters === 'resolveAssociativeParameters') {
                        $this->classResource[$class->getName()]['constructor']['params'][$passable[0]->getName()] = $supplied;
                    } else {
                        $this->classResource[$class->getName()]['constructor']['params'] = array_merge(
                            [$supplied], $this->classResource[$class->getName()]['constructor']['params'] ?? []
                        );
                    }
                    $incrementBy = 1;
                }
                return [$incrementBy, $this->getResolvedInstance($class, $supplied)['instance']];
            }
        }
        return [0, $this->stdClass];
    }

    /**
     * Get class Instance
     *
     * @param ReflectionClass $class
     * @param array $params
     * @return object|null
     * @throws ReflectionException|Exception
     */
    private function getClassInstance(ReflectionClass $class, array $params = []): ?object
    {
        if ($class->isInterface()) {
            throw new Exception("Expecting class, interface ({$class->getName()}) found instead!");
        }
        $constructor = $class->getConstructor();
        return $constructor === null ?
            $class->newInstanceWithoutConstructor() :
            $class->newInstanceArgs(
                $this->{$this->resolveParameters}($constructor, $params, 'constructor')
            );
    }

    /**
     * Get Method return
     *
     * @param object|null $classInstance
     * @param string $method
     * @param array $params
     * @return mixed
     * @throws ReflectionException
     */
    private function invokeMethod(?object $classInstance, string $method, array $params = []): mixed
    {
        $method = new ReflectionMethod(get_class($classInstance), $method);
        if ($this->allowPrivateMethodAccess) {
            $method->setAccessible(true);
        }
        return $method->invokeArgs(
            $classInstance,
            $this->{$this->resolveParameters}($method, $params, 'method')
        );
    }

    /**
     * Check & get Reflection instance
     *
     * @param ReflectionParameter $parameter
     * @param string $methodType
     * @return ReflectionClass|null
     * @throws ReflectionException
     */
    private function resolveClass(ReflectionParameter $parameter, string $methodType): ?ReflectionClass
    {
        $type = $parameter->getType();
        $name = $parameter->getName();
        return match (true) {
            $type instanceof ReflectionNamedType && !$type->isBuiltin()
            => new ReflectionClass($this->getClassName($parameter, $type->getName())),

            $this->check($methodType, $name)
            => new ReflectionClass($this->functionReference[$methodType][$name]),

            $this->check('common', $name)
            => new ReflectionClass($this->functionReference['common'][$name]),

            default => null
        };
    }

    /**
     * Check if specified class exists
     *
     * @param string $type
     * @param string $name
     * @return bool
     */
    private function check(string $type, string $name): bool
    {
        return isset($this->functionReference[$type][$name]) &&
            class_exists($this->functionReference[$type][$name], true);
    }

    /**
     * Get the class name for given type
     *
     * @param ReflectionParameter $parameter
     * @param string $name
     * @return string
     */
    private function getClassName(ReflectionParameter $parameter, string $name): string
    {
        if (($class = $parameter->getDeclaringClass()) !== null) {
            return match (true) {
                $name === 'self' => $class->getName(),
                $name === 'parent' && ($parent = $class->getParentClass()) => $parent->getName(),
                default => $name
            };
        }
        return $name;
    }

    /**
     * Check if parameter already resolved
     *
     * @param string $class
     * @param array $parameters
     * @return bool
     */
    private function alreadyExist(string $class, array $parameters): bool
    {
        foreach ($parameters as $value) {
            if ($value instanceof $class) {
                return true;
            }
        }
        return false;
    }
}
