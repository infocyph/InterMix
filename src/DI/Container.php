<?php


namespace AbmmHasan\OOF\DI;

use Closure;
use Exception;
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
    private static string $class;
    private string $dynClass;
    private array $constructorParams;
    private array $functionReference = [];
    private stdClass $stdClass;
    private array $classOrClosure;
    private bool $enableParameterPassing = false;
    private bool $allowPrivateMethodAccess = false;

    /**
     * Set Class & Constructor parameters.
     *
     * @param string $class
     * @param $parameters
     */
    public function __construct(string $class, $parameters)
    {
        self::$class = $class;
        $this->constructorParams = $parameters;
        $this->stdClass = new stdClass();
    }

    public function registerClass(string $classOrClosure, array $parameters = [])
    {
        $this->classOrClosure[$classOrClosure]['constructor'] = [
            'on' => '__constructor',
            'params' => $parameters
        ];
        return $this;
    }

    public function registerMethod(string $class, $method, array $parameters = [])
    {
        if (!isset($this->classOrClosure[$class])) {
            throw new Exception("Class not registered!");
        }
        if ($class instanceof Closure) {
            throw new Exception("Method not allowed in Closure!");
        }
        $this->classOrClosure[$class]['method'] = [
            'on' => $method,
            'params' => $parameters
        ];
        return $this;
    }

    public function enableParameterPass()
    {
        $this->enableParameterPassing = true;
        return $this;
    }

    public function allowPrivateMethodAccess()
    {
        $this->allowPrivateMethodAccess = true;
        return $this;
    }

    /**
     * Set resource for parameter to Class Method resolver
     *
     * @param string $parameterType
     * @param array $parameterResource
     * @return Container
     * @throws Exception
     */
    public function registerAlias(string $parameterType, array $parameterResource)
    {
        if (!in_array($parameterType, ['constructor', 'method', 'common'])) {
            throw new Exception("$parameterType is invalid!");
        }
        $this->functionReference[$parameterType] = $parameterResource;
        return $this;
    }

    public function call($class, $method)
    {

    }

    /**
     * Class Method Resolver with dependency Injection
     *
     * @param $method
     * @param $parameters
     * @return mixed
     * @throws ReflectionException
     */
    public function __call($method, $parameters)
    {
        return $this->__withInjection(self::$class, $method, $this->__flatten($parameters));
    }

    /**
     * Flatten array
     *
     * @param $array
     * @return array
     */
    private static function __flatten($array): array
    {
        return iterator_to_array(
            new RecursiveIteratorIterator(new RecursiveArrayIterator($array))
        );
    }

    /**
     * Resolve with injection
     *
     * @param $class
     * @param $method
     * @param $params
     * @return mixed
     * @throws ReflectionException
     */
    private function __withInjection($class, $method, $params): mixed
    {
        if ($method === 'closure' && $class instanceof Closure) {
            return call_user_func_array(
                $class,
                $this->resolveParameters(new ReflectionFunction($class), $this->constructorParams, 'constructor')
            );
        }
        return $this->getResolvedObject($class, $method, $this->constructorParams, $params);
    }

    /**
     * @param $class
     * @param $method
     * @param $constructorParams
     * @param $methodParams
     * @return mixed
     * @throws ReflectionException
     */
    private function getResolvedObject($class, $method, $constructorParams, $methodParams)
    {
        return $this->invokeMethod(
            $this->getClassInstance(new ReflectionClass($class), $constructorParams),
            $method,
            $methodParams
        );
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
            return $this->getResolvedInstance($class);
        }
        return $this->stdClass;
    }

    private function getResolvedInstance($class)
    {
        $method = $this->classOrClosure[$class->getName()]['method']['on'] ?? $class->getConstant('callOn');
        $instance = $this->getClassInstance($class, $this->classOrClosure[$class->getName()]['constructor']['params'] ?? []);
        if ($method && $class->hasMethod($method)) {
            $this->invokeMethod($instance, $method, $this->classOrClosure[$class->getName()]['method']['params'] ?? []);
        }
        return $instance;
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
