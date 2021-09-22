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
     * @param ...$parameters
     */
    public function __construct(string $class, ...$parameters)
    {
        self::$class = $class;
        $this->constructorParams = $this->__flatten($parameters);
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
                    $class->newInstanceArgs($this->__resolveParameters($constructor, $this->constructorParams, 'constructor')),
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
            $instance = $this->__resolveDependency($classParameter, $suppliedParameters, $type);
            switch (true) {
                case $instance !== $this->stdClass:
                    $instanceCount++;
                    $processed[$classParameter->getName()] = $instance;
                    break;
                case !isset($values[$key - $instanceCount]) && $classParameter->isDefaultValueAvailable():
                    $processed[$classParameter->getName()] = $classParameter->getDefaultValue();
                    break;
                default:
                    $processed[$classParameter->getName()] = $suppliedParameters[$classParameter->getName()];
            }
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
        $class = $this->resolveClassOrParameter($parameter, $type);
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
     *
     * @param $parameter
     * @param $type
     * @return ReflectionClass|null
     * @throws ReflectionException
     */
    private function resolveClassOrParameter($parameter, $type): ?ReflectionClass
    {
        return match (true) {
            $parameter->getType() && !$parameter->getType()->isBuiltin() => new ReflectionClass($parameter->getType()->getName()),

            isset($this->functionReference[$type][$parameter]) &&
            class_exists($this->functionReference[$type][$parameter], false) => new ReflectionClass($this->functionReference[$type][$parameter]),

            isset($this->functionReference['common'][$parameter]) &&
            class_exists($this->functionReference['common'][$parameter], false) => new ReflectionClass($this->functionReference['common'][$parameter]),

            default => null,
        };
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
