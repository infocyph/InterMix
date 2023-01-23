<?php

namespace AbmmHasan\OOF\DI\Resolver;

use AbmmHasan\OOF\DI\Asset;
use Closure;
use Exception;
use ReflectionClass;
use ReflectionException;
use ReflectionFunction;
use ReflectionFunctionAbstract;
use ReflectionMethod;
use ReflectionNamedType;
use ReflectionParameter;

final class DependencyResolver
{
    private array $resolvedDefinition = [];

    public function __construct(
        private Asset $containerAsset
    ) {
    }

    /**
     * Settle class dependency and resolve thorough
     *
     * @param string $class
     * @param string|null $method
     * @return object
     * @throws ReflectionException
     */
    public function classSettler(string $class, string $method = null): object
    {
        return (object)$this->getResolvedInstance(
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
    public function closureSettler(string|Closure $closure, array $params = []): mixed
    {
        return $closure(
            ...$this->resolveParameters(
            new ReflectionFunction($closure),
            $params,
            'constructor'
        )
        );
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
        mixed $supplied = null,
        string $callMethod = null
    ): array {
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
                $class,
                $this->containerAsset->classResource[$className]['constructor']['params'] ?? []
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
    private function resolveParameters(
        ReflectionFunctionAbstract $reflector,
        array $suppliedParameters,
        string $type
    ): array {
        $processed = [];
        $instanceCount = 0;
        $values = array_values($suppliedParameters);
        $parent = $reflector->class ?? $reflector->getName();
        foreach ($reflector->getParameters() as $key => $classParameter) {
            $parameterName = $classParameter->getName();

            if (array_key_exists($parameterName, $this->containerAsset->functionReference)) {
                $processed[$classParameter->getName()] = $this->resolveByDefinition(
                    $this->containerAsset->functionReference[$parameterName],
                    $parameterName
                );
                continue;
            }

            $class = $this->getResolvableClassReflection($classParameter);
            $passableParameter = $suppliedParameters[$parameterName] ?? null;

            if ($class) {
                if ($this->alreadyExist($class->name, $processed)) {
                    throw new Exception(
                        "Found multiple instances for $class->name in $parent::{$reflector->getShortName()}()"
                    );
                }
                if ($type === 'constructor' && $classParameter->getDeclaringClass()?->getName() === $class->name) {
                    throw new Exception("Looped call detected: $class->name");
                }
                $processed[$classParameter->getName()] = $this->resolveClassDependency(
                    $class,
                    $type,
                    $passableParameter
                );
                $instanceCount++;
                continue;
            }

            if (!isset($values[$key - $instanceCount]) && $classParameter->isDefaultValueAvailable()) {
                $processed[$classParameter->getName()] = $classParameter->getDefaultValue();
                continue;
            }

            $processed[$classParameter->getName()] = $passableParameter ?? throw new Exception(
                "Resolution failed: '$parameterName' of $parent::{$reflector->getShortName()}()"
            );
            $instanceCount++;
        }
        return $processed;
    }

    /**
     * Definition based resolver
     *
     * @param mixed $definition
     * @param string $name
     * @return mixed
     * @throws ReflectionException
     */
    public function resolveByDefinition(mixed $definition, string $name): mixed
    {
        return $this->resolvedDefinition[$name] ??= match (true) {
            $definition instanceof Closure => $this->closureSettler($$name = $definition),

            is_array($definition) && class_exists($definition[0]) => function () use ($definition) {
                $resolved = $this->classSettler(...$definition);
                return empty($definition[1]) ? $resolved['instance'] : $resolved['returned'];
            },

            is_string($definition) && class_exists($definition) => $this->classSettler($definition)['instance'],

            default => $definition
        };
    }

    /**
     * Resolve class dependency
     *
     * @param ReflectionClass $class
     * @param string $type
     * @param mixed $supplied
     * @return object
     * @throws ReflectionException
     */
    private function resolveClassDependency(
        ReflectionClass $class,
        string $type,
        mixed $supplied
    ): object {
        if (
            $type === 'constructor' && $supplied !== null &&
            ($constructor = $class->getConstructor()) !== null &&
            count($passable = $constructor->getParameters())
        ) {
            $this->containerAsset->classResource[$class->getName()]['constructor']['params']
            [$passable[0]->getName()] = $supplied;
        }
        return $this->getResolvedInstance($class, $supplied)['instance'];
    }

    /**
     * Get reflection instance, if applicable
     *
     * @param ReflectionParameter $parameter
     * @return ReflectionClass|null
     * @throws ReflectionException
     */
    private function getResolvableClassReflection(ReflectionParameter $parameter): ?ReflectionClass
    {
        $type = $parameter->getType();
        if ($type instanceof ReflectionNamedType && !$type->isBuiltin()) {
            return $this->reflectedClass($this->getClassName($parameter, $type->getName()));
        }

        return null;
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
                $this->resolveParameters($constructor, $params, 'constructor')
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
        $method->setAccessible($this->containerAsset->allowPrivateMethodAccess);
        return $method->invokeArgs(
            $classInstance,
            $this->resolveParameters($method, $params, 'method')
        );
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
