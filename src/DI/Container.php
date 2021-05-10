<?php


namespace AbmmHasan\OOF\DI;

use Closure;
use ReflectionClass;
use ReflectionException;
use ReflectionFunction;
use ReflectionFunctionAbstract;
use ReflectionMethod;

/**
 * The Container class can inject dependency while calling class methods (for both method & constructor)
 * This also supports closure
 *
 * Usage examples can be found in the included README file, and all methods
 * should have adequate documentation to get you started.
 */
class Container
{
    private $class;
    private $constructorParams;
    private $resolveDependency = true;

    /**
     * Set Class & Constructor parameters.
     *
     * @param $class
     * @param mixed ...$parameters
     */
    public function __construct($class, ...$parameters)
    {
        $this->class = $class;
        $this->constructorParams = $parameters;
    }

    /**
     * Call this after constructor, if don't need dependency resolved
     */
    public function _noInject()
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
        if ($this->resolveDependency === true) {
            return $this->_withInjection($method, $params);
        }
        return $this->_withoutInjection($method, $params);
    }

    /**
     * Resolve without Injection
     *
     * @param $method
     * @param $params
     * @return mixed
     */
    private function _withoutInjection($method, $params)
    {
        if ($method === 'closure' && $this->class instanceof Closure) {
            return ($this->class)(...$this->constructorParams);
        } else {
            return (new $this->class())->$method(...$params);
        }
    }

    /**
     * Resolve with injection
     *
     * @param $method
     * @param $params
     * @return mixed
     * @throws ReflectionException
     */
    private function _withInjection($method, $params)
    {
        if ($method === 'closure' && $this->class instanceof Closure) {
            return ($this->class)(...($this->_resolveParameters(new ReflectionFunction($this->class), $this->constructorParams)));
        } else {
            $constructor = (new ReflectionClass($this->class))->getConstructor();
            if (is_null($constructor)) {
                return (new $this->class())->$method(
                    ...
                    ($this->_resolveParameters(new ReflectionMethod($this->class, $method), $params))
                );
            }
            return (new $this->class(
                ...
                $this->_resolveParameters($constructor, $this->constructorParams)
            ))->$method(
                ...
                ($this->_resolveParameters(new ReflectionMethod($this->class, $method), $params))
            );
        }
    }

    /**
     * Resolve Function parameter
     *
     * @param array $parameters
     * @param ReflectionFunctionAbstract $reflector
     * @return array
     * @throws ReflectionException
     */
    private function _resolveParameters(ReflectionFunctionAbstract $reflector, array $parameters): array
    {
        $instanceCount = 0;
        $values = array_values($parameters);
        $skipValue = new \stdClass();
        foreach ($reflector->getParameters() as $key => $parameter) {
            $instance = $this->_resolveDependency($parameter, $parameters, $skipValue);
            if ($instance !== $skipValue) {
                $instanceCount++;
                array_splice(
                    $parameters,
                    $key,
                    0,
                    [$instance]
                );
            } elseif (!isset($values[$key - $instanceCount]) &&
                $parameter->isDefaultValueAvailable()) {
                array_splice(
                    $parameters,
                    $key,
                    0,
                    [$parameter->getDefaultValue()]
                );
            }
        }
        return $parameters;
    }

    /**
     * Resolve parameter dependency
     *
     * @param \ReflectionParameter $parameter
     * @param $parameters
     * @param $skipValue
     * @return object|null
     * @throws ReflectionException
     */
    private function _resolveDependency(\ReflectionParameter $parameter, $parameters, $skipValue): ?object
    {
        $class = $parameter->getClass();
        if ($class && !$this->_alreadyExist($class->name, $parameters)) {
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
    private function _alreadyExist($class, array $parameters): ?bool
    {
        foreach ($parameters as $value) {
            if ($value instanceof $class) {
                return !is_null($value);
            }
        }
    }
}
