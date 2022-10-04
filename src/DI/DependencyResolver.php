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

final class DependencyResolver
{
    private stdClass $stdClass;

    public function __construct(
        private Asset $containerAsset
    )
    {
        $this->stdClass = new stdClass();
    }

    /**
     * Settle class dependency and resolve thorough
     *
     * @param string $class
     * @param string|null $method
     * @return array
     * @throws ReflectionException
     */
    public function classSettler(string $class, string $method = null): array
    {
        return $this->getResolvedInstance(
            $this->reflectedClass($class),
            null,
            $method
        );
    }

    /**
     * Settle closure dependency and resolve thorough
     *
     * @param string|Closure $closure
     * @param array $params
     * @return mixed
     * @throws ReflectionException
     */
    public function closureSettler(string|Closure $closure, array $params): mixed
    {
        return $closure(...$this->{$this->containerAsset->resolveParameters}(
            new ReflectionFunction($closure),
            $params,
            'constructor'
        ));
    }

    /**
     * Get resolved Instance & method
     *
     * @param ReflectionClass $class
     * @param mixed|null $supplied
     * @param string|null $callMethod
     * @return array
     * @throws ReflectionException|Exception
     */
    private function getResolvedInstance(
        ReflectionClass $class,
        mixed           $supplied = null,
        string          $callMethod = null
    ): array
    {
        $className = $class->getName();
        if ($class->isInterface()) {
            if (!class_exists($supplied)) {
                throw new Exception("Resolution failed: $supplied for interface $className");
            }
            [$interface, $className] = [$className, $supplied];
            $class = $this->reflectedClass($className);
            if (!$class->implementsInterface($interface)) {
                throw new Exception("$className doesn't implement $interface");
            }
        }

        if (!$this->containerAsset->forceSingleton || !isset($this->containerAsset->resolvedResource[$className]['instance'])) {
            $this->containerAsset->resolvedResource[$className]['instance'] = $this->getClassInstance(
                $class, $this->containerAsset->classResource[$className]['constructor']['params'] ?? []
            );
        }

        $this->containerAsset->resolvedResource[$className]['returned'] = null;
        if ($callMethod === false) {
            return $this->containerAsset->resolvedResource[$className];
        }

        $method = $callMethod
            ?? $this->containerAsset->classResource[$className]['method']['on']
            ?? ($class->getConstant('callOn') ?: $this->containerAsset->defaultMethod);
        if (!empty($method) && $class->hasMethod($method)) {
            $this->containerAsset->resolvedResource[$className]['returned'] = $this->invokeMethod(
                $this->containerAsset->resolvedResource[$className]['instance'],
                $method,
                $this->containerAsset->classResource[$className]['method']['params'] ?? []
            );
        }
        return $this->containerAsset->resolvedResource[$className];
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
     * @param ReflectionParameter $parameter
     * @param array $parameters
     * @param string $type
     * @param mixed $supplied
     * @return array
     * @throws ReflectionException|Exception
     */
    private function resolveDependency(
        ReflectionParameter $parameter,
        array               $parameters,
        string              $type,
        mixed               $supplied
    ): array
    {
        if ($class = $this->resolveClass($parameter, $type)) {
            if ($type === 'constructor' && $parameter->getDeclaringClass()?->getName() === $class->name) {
                throw new Exception("Looped call detected: $class->name");
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
                    if ($this->containerAsset->resolveParameters === 'resolveAssociativeParameters') {
                        $this->containerAsset->classResource[$class->getName()]['constructor']['params'][$passable[0]->getName()] = $supplied;
                    } else {
                        $this->containerAsset->classResource[$class->getName()]['constructor']['params'] = array_merge(
                            [$supplied], $this->containerAsset->classResource[$class->getName()]['constructor']['params'] ?? []
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
        if (!$class->isInstantiable()) {
            throw new Exception("{$class->getName()} is not instantiable!");
        }
        $constructor = $class->getConstructor();
        return $constructor === null ?
            $class->newInstanceWithoutConstructor() :
            $class->newInstanceArgs(
                $this->{$this->containerAsset->resolveParameters}($constructor, $params, 'constructor')
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
        if ($this->containerAsset->allowPrivateMethodAccess) {
            $method->setAccessible(true);
        }
        return $method->invokeArgs(
            $classInstance,
            $this->{$this->containerAsset->resolveParameters}($method, $params, 'method')
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
            => $this->reflectedClass($this->getClassName($parameter, $type->getName())),

            $this->check($methodType, $name)
            => $this->reflectedClass($this->containerAsset->functionReference[$methodType][$name]),

            $this->check('common', $name)
            => $this->reflectedClass($this->containerAsset->functionReference['common'][$name]),

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
        return isset($this->containerAsset->functionReference[$type][$name]) &&
            class_exists($this->containerAsset->functionReference[$type][$name]);
    }

    /**
     * Get ReflectionClass instance
     *
     * @param string $className
     * @return ReflectionClass
     * @throws ReflectionException
     */
    private function reflectedClass(string $className): ReflectionClass
    {
        return $this->containerAsset->resolvedResource[$className]['reflection'] ??= new ReflectionClass($className);
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
