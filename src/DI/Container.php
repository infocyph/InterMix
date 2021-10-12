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
    private array $constructorParams;
    private array $functionReference = [];
    private stdClass $stdClass;

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

    /**
     * Set resource for parameter to Class Method resolver
     *
     * @param string $parameterType
     * @param array $parameterResource
     * @throws Exception
     */
    public function __set(string $parameterType, array $parameterResource)
    {
        if (!in_array($parameterType, ['constructor', 'method', 'common'])) {
            throw new Exception("$parameterType is invalid!");
        }
        $this->functionReference[$parameterType] = $parameterResource;
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
     * Class Method Resolver
     *
     * @param $method
     * @param $parameters
     * @return mixed
     */
    public static function __callStatic($method, $parameters)
    {
        return self::__withoutInjection(self::$class, $method, self::__flatten($parameters));
    }

    /**
     * Set resource for parameter to Class Method resolver
     *
     * @param string $parameterType
     * @param array $parameterResource
     * @throws Exception
     */
    public function __registerAlias(string $parameterType, array $parameterResource)
    {
        if (!in_array($parameterType, ['constructor', 'method', 'common'])) {
            throw new Exception("$parameterType is invalid!");
        }
        $this->functionReference[$parameterType] = $parameterResource;
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
     * Resolve without Injection
     *
     * @param $class
     * @param $method
     * @param $params
     * @return mixed
     */
    private static function __withoutInjection($class, $method, $params): mixed
    {
        return call_user_func_array(
            ($method === 'handle' && $class instanceof Closure) ?
                $class :
                [new self::$class(), $method],
            $params
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
                $this->__resolveParameters(new ReflectionFunction($class), $this->constructorParams, 'constructor')
            );
        }
        return $this->__callClass(new ReflectionClass($class), $method, $params);
    }

    /**
     * Call Class & Method
     *
     * @param $class
     * @param $method
     * @param $params
     * @return mixed
     * @throws ReflectionException
     */
    private function __callClass($class, $method, $params): mixed
    {
        $constructor = $class->getConstructor();
        return call_user_func_array(
            [
                is_null($constructor) ?
                    $class->newInstance() :
                    call_user_func_array(
                        [
                            $class,
                            'newInstance'
                        ],
                        $this->__resolveParameters($constructor, $this->constructorParams)
                    ),
                $method
            ],
            $this->__resolveParameters(new ReflectionMethod($class->getName(), $method), $params)
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
    private function __resolveParameters(ReflectionFunctionAbstract $reflector, array $suppliedParameters, string $type = 'method'): array
    {
        $processed = [];
        $instanceCount = 0;
        $values = array_values($suppliedParameters);
        foreach ($reflector->getParameters() as $key => $classParameter) {
            $instance = $this->__resolveDependency($classParameter, $processed, $type);
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
    private function __resolveDependency(ReflectionParameter $parameter, $parameters, $type): ?object
    {
        $class = $this->resolveClass($parameter, $type);
        if ($class && !$this->__alreadyExist($class->name, $parameters)) {
            if ($parameter->isDefaultValueAvailable()) {
                return null;
            }
            $constructor = $class->getConstructor();
            $constants = $class->getConstant('callOn');
            $instance = is_null($constructor) ?
                $class->newInstance() :
                $class->newInstanceArgs($this->__resolveParameters($constructor, [], 'constructor'));
            if ($constants && $class->hasMethod($constants)) {
                $method = new ReflectionMethod($class->getName(), $constants);
                $method->setAccessible(true);
                $method->invokeArgs(
                    $instance,
                    $this->__resolveParameters($method, [])
                );
            }
            return $instance;
        }
        return $this->stdClass;
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
    private function __alreadyExist($class, array $parameters): bool
    {
        foreach ($parameters as $value) {
            if ($value instanceof $class) {
                return !is_null($value);
            }
        }
        return false;
    }
}
