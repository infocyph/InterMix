<?php


namespace AbmmHasan\OOF\DI;

use Closure;
use Exception;
use JetBrains\PhpStorm\ArrayShape;
use RecursiveArrayIterator;
use RecursiveIteratorIterator;
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
 */
final class Container
{
    private array $functionReference = [];
    private stdClass $stdClass;
    private array $classOrClosure;
    private bool $enableParameterPassing = false;
    private bool $allowPrivateMethodAccess = false;
    private static Container $instance;

    /**
     * Class Constructor
     */
    public function __construct()
    {
        $this->stdClass = new stdClass();
    }

    /**
     * @param $method
     * @param $parameter
     * @return mixed
     * @throws Exception
     */
    public static function __callStatic($method, $parameter)
    {
        if (!in_array($method, ['registerClass', 'registerMethod'])) {
            throw new Exception("Invalid method call!");
        }
        self::$instance = self::$instance ?? new self();
        return (self::$instance)->$method(...$parameter);
    }

    /**
     * @param $response_type
     * @param $parameters
     * @return mixed
     */
    public function __call($response_type, $parameters)
    {
        return (self::$instance)->$response_type(...$parameters);
    }

    /**
     * @param string $classOrClosure
     * @param array $parameters
     * @return Container
     */
    private function registerClass(string $classOrClosure, array $parameters = []): Container
    {
        $this->classOrClosure[$classOrClosure]['constructor'] = [
            'on' => '__constructor',
            'params' => $parameters
        ];
        return self::$instance;
    }

    /**
     * @param string $class
     * @param $method
     * @param array $parameters
     * @return Container
     * @throws Exception
     */
    private function registerMethod(string $class, $method, array $parameters = []): Container
    {
        if ($class instanceof Closure) {
            throw new Exception("Method not allowed in Closure!");
        }
        $this->classOrClosure[$class]['method'] = [
            'on' => $method,
            'params' => $parameters
        ];
        return self::$instance;
    }

    /**
     * @return Container
     */
    private function enableParameterPass(): Container
    {
        $this->enableParameterPassing = true;
        return self::$instance;
    }

    /**
     * @return Container
     */
    private function allowPrivateMethodAccess(): Container
    {
        $this->allowPrivateMethodAccess = true;
        return self::$instance;
    }

    /**
     * Set resource for parameter to Class Method resolver
     *
     * @param string $parameterType
     * @param array $parameterResource
     * @return Container
     * @throws Exception
     */
    private function registerAlias(string $parameterType, array $parameterResource): Container
    {
        if (!in_array($parameterType, ['constructor', 'method', 'common'])) {
            throw new Exception("$parameterType is invalid!");
        }
        $this->functionReference[$parameterType] = $parameterResource;
        return self::$instance;
    }

    /**
     * @param $class
     * @return mixed
     * @throws ReflectionException
     */
    private function call($class): mixed
    {
        if ($class instanceof Closure) {
//            ToDo:Closure
//            return call_user_func_array(
//                $class,
//                $this->resolveParameters(new ReflectionFunction($class), $this->constructorParams, 'constructor')
//            );
        }
        return $this->getResolvedInstance(new ReflectionClass($class))['returned'];
    }

    /**
     * @param $class
     * @return array
     * @throws ReflectionException
     */
    #[ArrayShape(['instance' => "mixed", 'returned' => "mixed|null"])]
    private function getResolvedInstance($class): array
    {
        $method = $this->classOrClosure[$class->getName()]['method']['on'] ?? $class->getConstant('callOn');
        $instance = $this->getClassInstance($class, $this->classOrClosure[$class->getName()]['constructor']['params'] ?? []);
        $return = null;
        if ($method && $class->hasMethod($method)) {
            $return = $this->invokeMethod($instance, $method, $this->classOrClosure[$class->getName()]['method']['params'] ?? []);
        }
        return [
            'instance' => $instance,
            'returned' => $return
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
    private function resolveParameters(ReflectionFunctionAbstract $reflector, array $suppliedParameters, string $type): array
    {
        $processed = [];
        $instanceCount = 0;
        $values = array_values($suppliedParameters);
        foreach ($reflector->getParameters() as $key => $classParameter) {
            $instance = $this->resolveDependency($classParameter, $processed, $type);
            $processed[$classParameter->getName()] = match (true) {
                $instance !== $this->stdClass
                => [$instance, $instanceCount++][0],

                !isset($values[$key - $instanceCount]) && $classParameter->isDefaultValueAvailable()
                => $classParameter->getDefaultValue(),

                default => $suppliedParameters[$classParameter->getName()] ??
                    throw new Exception(
                        "Resolution failed: '{$classParameter->getName()}' of $reflector->class::{$reflector->getShortName()}()!"
                    )
            };
        }
        return $processed;
    }

    /**
     * Resolve parameter dependency
     *
     * @param ReflectionParameter $parameter
     * @param $parameters
     * @param $type
     * @return object|null
     * @throws ReflectionException
     */
    private function resolveDependency(ReflectionParameter $parameter, $parameters, $type): ?object
    {
        $class = $this->resolveClass($parameter, $type);
        if ($class && !$this->alreadyExist($class->name, $parameters)) {
            if ($parameter->isDefaultValueAvailable()) {
                return null;
            }
            return $this->getResolvedInstance($class)['instance'];
        }
        return $this->stdClass;
    }

    /**
     * @param $class
     * @param array $params
     * @return mixed
     * @throws ReflectionException
     */
    private function getClassInstance($class, array $params = []): mixed
    {
        $constructor = $class->getConstructor();
        return is_null($constructor) ?
            $class->newInstance() :
            $class->newInstanceArgs(
                $this->resolveParameters($constructor, $params, 'constructor')
            );
    }

    /**
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
            $this->resolveParameters($method, $params, 'method')
        );
    }

    /**
     * Check & get Reflection instance
     *
     * @param $parameter
     * @param $methodType
     * @return ReflectionClass|null
     * @throws ReflectionException
     */
    private function resolveClass($parameter, $methodType): ?ReflectionClass
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
     * Check if specific class exists
     *
     * @param $type
     * @param $name
     * @return bool
     */
    private function check($type, $name): bool
    {
        return isset($this->functionReference[$type][$name]) &&
            class_exists($this->functionReference[$type][$name], false);
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
        if (!is_null($class = $parameter->getDeclaringClass())) {
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
                return !is_null($value);
            }
        }
        return false;
    }
}
