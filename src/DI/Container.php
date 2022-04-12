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
 *
 * @method static Container registerClass(string $class, array $parameters = []) Register Class with constructor Parameter
 * @method static Container registerMethod(string $class, string $method, array $parameters = []) Register Class and Method (with method parameter)
 * @method static Container registerClosure($closureAlias, Closure $function, array $parameters = []) Register Closure
 * @method static Container registerParamToClass(array $parameterResource) Set resource for parameter to Class Constructor/Method resolver
 * @method static Container registerParamToConstructor(array $parameterResource) Set resource for parameter to Class Constructor resolver
 * @method static Container registerParamToMethod(array $parameterResource) Set resource for parameter to Class Method resolver
 * @method static Container allowPrivateMethodAccess() Allow access to private methods
 * @method static Container disableNamedParameter() Allow access to private methods
 * @method static mixed getInstance($class) Get Class Instance
 * @method static mixed callClosure($closureAlias) Call the desired closure
 * @method static mixed callMethod($class) Call the desired class (along with the method)
 */
final class Container
{
    private array $functionReference = [];
    private stdClass $stdClass;
    private array $classResource = [];
    private bool $allowPrivateMethodAccess = false;
    private static Container $instance;
    private array $closureResource = [];
    private string $resolveParameters = 'resolveAssociativeParameters';

    /**
     * Class Constructor
     */
    public function __construct()
    {
        $this->stdClass = new stdClass();
    }

    /**
     * @param $method
     * @param $parameters
     * @return mixed
     * @throws Exception
     */
    public static function __callStatic($method, $parameters)
    {
        self::$instance = self::$instance ?? new self();
        return (self::$instance)->callSelf($method, $parameters);
    }

    /**
     * @param $method
     * @param $parameters
     * @return mixed
     * @throws Exception
     */
    public function __call($method, $parameters)
    {
        return (self::$instance)->callSelf($method, $parameters);
    }

    /**
     * @param $method
     * @param $parameters
     * @return mixed
     * @throws Exception
     */
    private function callSelf($method, $parameters): mixed
    {
        [
            'registerClass' => '',
            'registerMethod' => '',
            'registerClosure' => '',
            'allowPrivateMethodAccess' => '',
            'registerParamToClass' => '',
            'registerParamToConstructor' => '',
            'registerParamToMethod' => '',
            'disableNamedParameter' => '',
            'getInstance' => '',
            'callClosure' => '',
            'callMethod' => ''
        ][$method] ?? throw new Exception('Invalid method call!');
        $method = "_$method";
        return (self::$instance)->$method(...$parameters);
    }

    /**
     * Register Closure
     *
     * @param string $closureAlias
     * @param Closure $function
     * @param array $parameters
     * @return Container
     */
    private function _registerClosure(string $closureAlias, Closure $function, array $parameters = []): Container
    {
        $this->closureResource[$closureAlias] = [
            'on' => $function,
            'params' => $parameters
        ];
        return self::$instance;
    }

    /**
     * Register Class with constructor Parameter
     *
     * @param string $class
     * @param array $parameters
     * @return Container
     */
    private function _registerClass(string $class, array $parameters = []): Container
    {
        $this->classResource[$class]['constructor'] = [
            'on' => '__constructor',
            'params' => $parameters
        ];
        return self::$instance;
    }

    /**
     * Register Class and Method with Parameter (method parameter)
     *
     * @param string $class
     * @param string $method
     * @param array $parameters
     * @return Container
     */
    private function _registerMethod(string $class, string $method, array $parameters = []): Container
    {
        $this->classResource[$class]['method'] = [
            'on' => $method,
            'params' => $parameters
        ];
        return self::$instance;
    }

    /**
     * Set resource for parameter to Class Constructor/Method resolver
     *
     * @param array $parameterResource
     * @return Container
     */
    private function _registerParamToClass(array $parameterResource): Container
    {
        $this->functionReference['common'] = $parameterResource;
        return self::$instance;
    }

    /**
     * Set resource for parameter to Class Constructor resolver
     *
     * @param array $parameterResource
     * @return Container
     */
    private function _registerParamToConstructor(array $parameterResource): Container
    {
        $this->functionReference['constructor'] = $parameterResource;
        return self::$instance;
    }

    /**
     * Set resource for parameter to Class Method resolver
     *
     * @param array $parameterResource
     * @return Container
     */
    private function _registerParamToMethod(array $parameterResource): Container
    {
        $this->functionReference['method'] = $parameterResource;
        return self::$instance;
    }

    /**
     * Allow access to private methods
     *
     * @return Container
     */
    private function _allowPrivateMethodAccess(): Container
    {
        $this->allowPrivateMethodAccess = true;
        return self::$instance;
    }

    /**
     * Disable resolution by name (instead it will resolve in sequence)
     *
     * @return Container
     */
    private function _disableNamedParameter(): Container
    {
        $this->resolveParameters = 'resolveNonAssociativeParameters';
        return self::$instance;
    }

    /**
     * Call the desired closure
     *
     * @param string|Closure $closureAlias
     * @return mixed
     * @throws ReflectionException|Exception
     */
    private function _callClosure(string|Closure $closureAlias): mixed
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
    private function _callMethod(string $class): mixed
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
    private function _getInstance(string $class): mixed
    {
        return $this->getResolvedInstance(new ReflectionClass($class))['instance'];
    }

    /**
     * Get resolved Instance & method
     *
     * @param $class
     * @return array
     * @throws ReflectionException
     */
    private function getResolvedInstance($class): array
    {
        $method = $this->classResource[$class->getName()]['method']['on'] ?? $class->getConstant('callOn') ?? false;
        $instance = $this->getClassInstance(
            $class,
            $this->classResource[$class->getName()]['constructor']['params'] ?? []
        );
        $return = null;
        if (!empty($method) && $class->hasMethod($method)) {
            $return = $this->invokeMethod(
                $instance,
                $method,
                $this->classResource[$class->getName()]['method']['params'] ?? []
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
        ?string             $supplied
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
                return [$incrementBy, $this->getResolvedInstance($class)['instance']];
            }
        }
        return [0, $this->stdClass];
    }

    /**
     * Get class Instance
     *
     * @param $class
     * @param array $params
     * @return mixed
     * @throws ReflectionException
     */
    private function getClassInstance($class, array $params = []): mixed
    {
        $constructor = $class->getConstructor();
        return $constructor === null ?
            $class->newInstance() :
            $class->newInstanceArgs(
                $this->{$this->resolveParameters}($constructor, $params, 'constructor')
            );
    }

    /**
     * Get Method return
     *
     * @param $classInstance
     * @param $method
     * @param array $params
     * @return mixed
     * @throws ReflectionException
     */
    private function invokeMethod($classInstance, $method, array $params = []): mixed
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
     * @param $parameter
     * @param $methodType
     * @return object|null
     * @throws ReflectionException
     */
    private function resolveClass($parameter, $methodType): ?object
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
     * @param $type
     * @param $name
     * @return bool
     */
    private function check($type, $name): bool
    {
        return isset($this->functionReference[$type][$name]) &&
            class_exists($this->functionReference[$type][$name], true);
    }

    /**
     * Get the class name for given type
     *
     * @param $parameter
     * @param $name
     * @return string
     */
    private function getClassName($parameter, $name): string
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
     * @param $class
     * @param array $parameters
     * @return bool
     */
    private function alreadyExist($class, array $parameters): bool
    {
        foreach ($parameters as $value) {
            if ($value instanceof $class) {
                return true;
            }
        }
        return false;
    }
}
