<?php


namespace AbmmHasan\OOF\DI;

use Closure;
use ReflectionClass;
use ReflectionException;
use ReflectionFunction;
use ReflectionFunctionAbstract;
use ReflectionMethod;
use ReflectionParameter;
use stdClass;

/**
 * The Container class can inject dependency while calling class methods (for both method & constructor)
 * This also supports closure
 *
 * Usage examples can be found in the included README file, and all methods
 * should have adequate documentation to get you started.
 */
class Container
{
    private string $class;
    private array $constructorParams;
    private bool $resolveDependency = true;

    /**
     * Set Class & Constructor parameters.
     *
     * @param string $class
     * @param mixed ...$parameters
     */
    public function __construct(string $class, ...$parameters)
    {
        $this->class = $class;
        $this->constructorParams = $parameters;
    }

    /**
     * Call this after constructor, if you don't need dependency resolved
     */
    public function __noInject()
    {
        $this->resolveDependency = false;
    }

    /**
     * Class Method Resolver
     *
     * @param $method
     * @param $params
     * @return mixed
     * @throws ReflectionException
     */
    public function __call($method, $params)
    {
        return $this->resolveDependency === true ?
            $this->__withInjection($this->class, $method, $params) :
            $this->__withoutInjection($this->class, $method, $params);
    }

    /**
     * Resolve without Injection
     *
     * @param $class
     * @param $method
     * @param $params
     * @return mixed
     */
    private function __withoutInjection($class, $method, $params): mixed
    {
        return call_user_func_array(
            ($method === 'closure' && $class instanceof Closure) ?
                $class :
                [new $this->class(), $method],
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
                $this->__resolveParameters(new ReflectionFunction($class), $this->constructorParams)
            );
        }

        $classAsset = new ReflectionClass($class);
        $constructor = $classAsset->getConstructor();
        if (is_null($constructor)) {
            return call_user_func_array(
                [new $class(), $method],
                $this->__resolveParameters(new ReflectionMethod($class, $method), $params)
            );
        }
        return call_user_func_array(
            [
                call_user_func_array(
                    [$classAsset, 'newInstance'],
                    $this->__resolveParameters($constructor, $this->constructorParams)
                ),
                $method
            ],
            $this->__resolveParameters(new ReflectionMethod($class, $method), $params)
        );
    }

    /**
     * Resolve Function parameter
     *
     * @param array $parameters
     * @param ReflectionFunctionAbstract $reflector
     * @return array
     * @throws ReflectionException
     */
    private function __resolveParameters(ReflectionFunctionAbstract $reflector, array $parameters): array
    {
        $instanceCount = 0;
        $values = array_values($parameters);
        $skipValue = new stdClass();
        foreach ($reflector->getParameters() as $key => $parameter) {
            $instance = $this->__resolveDependency($parameter, $parameters, $skipValue);
            if ($instance !== $skipValue) {
                $instanceCount++;
                array_splice($parameters, $key, 0, [$instance]);
            } elseif (!isset($values[$key - $instanceCount]) &&
                $parameter->isDefaultValueAvailable()) {
                array_splice($parameters, $key, 0, [$parameter->getDefaultValue()]);
            }
        }
        return $parameters;
    }

    /**
     * Resolve parameter dependency
     *
     * @param ReflectionParameter $parameter
     * @param $parameters
     * @param $skipValue
     * @return object|null
     * @throws ReflectionException
     */
    private function __resolveDependency(ReflectionParameter $parameter, $parameters, $skipValue): ?object
    {
        $class = $parameter->getType() && !$parameter->getType()->isBuiltin()
            ? new ReflectionClass($parameter->getType()->getName())
            : null;
        if ($class && !$this->__alreadyExist($class->name, $parameters)) {
            return $parameter->isDefaultValueAvailable()
                ? null
                : $class->newInstance();
        }
        return $skipValue;
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
