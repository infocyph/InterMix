<?php

namespace AbmmHasan\OOF\DI\Resolver;

use AbmmHasan\OOF\DI\Asset;
use AbmmHasan\OOF\Exceptions\ContainerException;
use Closure;
use ReflectionClass;
use ReflectionException;
use ReflectionFunction;
use ReflectionFunctionAbstract;
use ReflectionMethod;
use ReflectionNamedType;
use ReflectionParameter;

abstract class DependencyResolver
{
    protected array $resolvedDefinition = [];

    public function __construct(
        protected Asset $containerAsset
    ) {
    }

    /**
     * Get resolved Instance & method
     *
     * @param ReflectionClass $class
     * @param mixed|null $supplied
     * @param string|bool|null $callMethod
     * @return array
     * @throws ContainerException
     * @throws ReflectionException
     */
    protected function getResolvedInstance(
        ReflectionClass $class,
        mixed $supplied = null,
        string|bool $callMethod = null
    ): array {
        $this->resolveClass($class, $supplied);
        $this->resolveMethod($class, $callMethod);

        return $this->containerAsset->resolvedResource[$class->getName()];
    }

    /**
     * Resolve Function parameter
     *
     * @param ReflectionFunctionAbstract $reflector
     * @param array $suppliedParameters
     * @param string $type
     * @return array
     * @throws ReflectionException|ContainerException
     */
    protected function resolveParameters(
        ReflectionFunctionAbstract $reflector,
        array $suppliedParameters,
        string $type
    ): array {
        $refMethod = ($reflector->class ?? $reflector->getName()) . "->{$reflector->getShortName()}()";
        $availableParams = $reflector->getParameters();

        // Resolve associative parameters
        [
            'availableParams' => $availableParams,
            'processed' => $processed,
            'availableSupply' => $suppliedParameters
        ] = $this->resolveAssociativeParameters($availableParams, $type, $suppliedParameters, $refMethod);

        // Resolve numeric & default parameters
        $this->resolveNumericDefaultParameters($processed, $availableParams, $suppliedParameters, $refMethod);

        return $processed;
    }

    /**
     * Get ReflectionClass instance
     *
     * @param string $className
     * @return ReflectionClass
     * @throws ReflectionException
     */
    protected function reflectedClass(string $className): ReflectionClass
    {
        return $this->containerAsset->resolvedResource[$className]['reflection'] ??= new ReflectionClass($className);
    }

    /**
     * Resolve class (initiate & construct)
     *
     * @param ReflectionClass $class
     * @param mixed $supplied
     * @return void
     * @throws ContainerException|ReflectionException
     */
    private function resolveClass(ReflectionClass $class, mixed $supplied): void
    {
        $className = $class->getName();
        if ($class->isInterface()) {
            if (!class_exists($supplied)) {
                throw new ContainerException("Resolution failed: $supplied for interface $className");
            }
            [$interface, $className] = [$className, $supplied];
            $class = $this->reflectedClass($className);
            if (!$class->implementsInterface($interface)) {
                throw new ContainerException("$className doesn't implement $interface");
            }
        }
        if (isset($this->containerAsset->resolvedResource[$className]['instance'])) {
            return;
        }
        if (!$class->isInstantiable()) {
            throw new ContainerException("{$class->getName()} is not instantiable!");
        }
        $constructor = $class->getConstructor();
        $this->containerAsset->resolvedResource[$className]['instance'] = $constructor === null ?
            $class->newInstanceWithoutConstructor() :
            $class->newInstanceArgs(
                $this->resolveParameters(
                    $constructor,
                    $this->containerAsset->classResource[$className]['constructor']['params'] ?? [],
                    'constructor'
                )
            );
    }

    /**
     * Resolve method
     *
     * @param ReflectionClass $class
     * @param string|bool $callMethod
     * @return void
     * @throws ContainerException
     * @throws ReflectionException
     */
    private function resolveMethod(ReflectionClass $class, string|bool $callMethod): void
    {
        $className = $class->getName();

        $this->containerAsset->resolvedResource[$className]['returned'] = null;
        if ($callMethod === false) {
            return;
        }

        $method = $callMethod
            ?? $this->containerAsset->classResource[$className]['method']['on']
            ?? ($class->getConstant('callOn') ?: $this->containerAsset->defaultMethod);

        if (!empty($method) && $class->hasMethod($method)) {
            $method = new ReflectionMethod($className, $method);
            $this->containerAsset->resolvedResource[$className]['returned'] = $method->invokeArgs(
                $this->containerAsset->resolvedResource[$className]['instance'],
                $this->resolveParameters(
                    $method,
                    $this->containerAsset->classResource[$className]['method']['params'] ?? [],
                    'method'
                )
            );
        }
    }

    /**
     * Resolve non-associative parameters
     *
     * @param array $processed
     * @param array $availableParams
     * @param array $suppliedParameters
     * @param string $refMethod
     * @return void
     * @throws ContainerException
     */
    private function resolveNumericDefaultParameters(
        array &$processed,
        array $availableParams,
        array $suppliedParameters,
        string $refMethod
    ): void {
        foreach ($availableParams as $key => $classParameter) {
            $parameterName = $classParameter->getName();

            if ($classParameter->isVariadic()) {
                $processed[$parameterName] = array_slice($suppliedParameters, $key);
                break;
            }

            if (!isset($suppliedParameters[$key])) {
                if ($classParameter->isDefaultValueAvailable()) {
                    $processed[$parameterName] = $classParameter->getDefaultValue();
                    continue;
                }
                if ($classParameter->getType() && $classParameter->allowsNull()) {
                    $processed[$parameterName] = null;
                    continue;
                }
                throw new ContainerException(
                    "Resolution failed for '$parameterName' in $refMethod"
                );
            }

            $processed[$parameterName] = $suppliedParameters[$key];
        }
    }

    /**
     * Resolve associative parameters
     *
     * @param array $availableParams
     * @param string $type
     * @param array $suppliedParameters
     * @param string $refMethod
     * @return array[]
     * @throws ContainerException|ReflectionException
     */
    private function resolveAssociativeParameters(
        array $availableParams,
        string $type,
        array $suppliedParameters,
        string $refMethod
    ): array {
        $processed = $paramsLeft = [];
        foreach ($availableParams as $classParameter) {
            $parameterName = $classParameter->getName();

            if ($classParameter->isVariadic()) {
                $paramsLeft[] = $classParameter;
                break;
            }

            if (array_key_exists($parameterName, $this->containerAsset->functionReference)) {
                $processed[$parameterName] = $this->resolveByDefinition(
                    $this->containerAsset->functionReference[$parameterName],
                    $parameterName
                );
                continue;
            }

            $class = $this->getResolvableClassReflection($classParameter, $type);
            if ($class) {
                if ($this->alreadyExist($class->name, $processed)) {
                    throw new ContainerException("Found multiple instances for $class->name in $refMethod");
                }
                $processed[$parameterName] = $this->resolveClassDependency(
                    $class,
                    $type,
                    $suppliedParameters[$parameterName] ?? null
                );
                continue;
            }

            if (array_key_exists($parameterName, $suppliedParameters)) {
                $processed[$parameterName] = $suppliedParameters[$parameterName];
                continue;
            }

            $paramsLeft[] = $classParameter;
        }

        return [
            'availableParams' => $paramsLeft,
            'processed' => $processed,
            'availableSupply' => array_values(
                array_filter($suppliedParameters, "is_int", ARRAY_FILTER_USE_KEY)
            )
        ];
    }

    /**
     * Resolve class dependency
     *
     * @param ReflectionClass $class
     * @param string $type
     * @param mixed $supplied
     * @return object
     * @throws ReflectionException|ContainerException
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
     * @param string $type
     * @return ReflectionClass|null
     * @throws ContainerException|ReflectionException
     */
    private function getResolvableClassReflection(ReflectionParameter $parameter, string $type): ?ReflectionClass
    {
        $parameterType = $parameter->getType();
        if (!$parameterType instanceof ReflectionNamedType || $parameterType->isBuiltin()) {
            return null;
        }
        $reflection = $this->reflectedClass($this->getClassName($parameter, $parameterType->getName()));

        if ($type === 'constructor' && $parameter->getDeclaringClass()?->getName() === $reflection->name) {
            throw new ContainerException("Circular dependency detected: $reflection->name");
        }

        return $reflection;
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
