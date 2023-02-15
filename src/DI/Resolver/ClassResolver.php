<?php

namespace AbmmHasan\OOF\DI\Resolver;

use AbmmHasan\OOF\Exceptions\ContainerException;
use ReflectionClass;
use ReflectionException;
use ReflectionMethod;
use ReflectionProperty;

class ClassResolver
{
    use ReflectionResource;

    /**
     * @param Repository $repository
     * @param ParameterResolver $parameterResolver
     */
    public function __construct(
        private Repository $repository,
        private ParameterResolver $parameterResolver
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
    public function resolve(
        ReflectionClass $class,
        mixed $supplied = null,
        string|bool $callMethod = null
    ): array {
        $class = $this->getClass($class, $supplied);

        if ($properties = $this->getResolvableProperties($class)) {
            $this->resolveProperties($class, $properties);
        }

        $this->resolveConstructor($class);
        $this->resolveMethod($class, $callMethod);

        return $this->repository->resolvedResource[$class->getName()];
    }

    /**
     * Get the actual class
     *
     * @param ReflectionClass $class
     * @param mixed $supplied
     * @return ReflectionClass
     * @throws ContainerException|ReflectionException
     */
    private function getClass(ReflectionClass $class, mixed $supplied): ReflectionClass
    {
        if ($class->isInterface()) {
            $className = $class->getName();
            if (!class_exists($supplied)) {
                throw new ContainerException("Resolution failed: $supplied for interface $className");
            }
            [$interface, $className] = [$className, $supplied];
            $class = $this->reflectedClass($className);
            if (!$class->implementsInterface($interface)) {
                throw new ContainerException("$className doesn't implement $interface");
            }
        }
        return $class;
    }

    /**
     * Get resolvable properties
     *
     * @param ReflectionClass $class
     * @return array
     */
    private function getResolvableProperties(ReflectionClass $class): array
    {
        $className = $class->getName();

        if (!isset($this->repository->classResource[$className]['property']) && !$this->repository->enableAttribute) {
            return [];
        }

        return $class->getProperties(ReflectionProperty::IS_PUBLIC | ReflectionProperty::IS_PROTECTED);
    }

    private function resolveProperties(ReflectionClass $class, array $properties): void
    {
        $className = $class->getName();
        $classPropertyValues = $this->repository->classResource[$className]['property'] ?? [];

        foreach ($properties as $property) {
            if (isset($classPropertyValues[$property->getName()])) {
                $property->setValue($classPropertyValues[$property->getName()]);
                continue;
            }
            if (!$this->repository->enableAttribute || $property->getAttributes() === []) {
                continue;
            }
        }
    }

    /**
     * Resolve class (initiate & construct)
     *
     * @param ReflectionClass $class
     * @return void
     * @throws ContainerException|ReflectionException
     */
    private function resolveConstructor(ReflectionClass $class): void
    {
        $className = $class->getName();
        if (isset($this->repository->resolvedResource[$className]['instance'])) {
            return;
        }
        if (!$class->isInstantiable()) {
            throw new ContainerException("{$class->getName()} is not instantiable!");
        }
        $constructor = $class->getConstructor();
        $this->repository->resolvedResource[$className]['instance'] = $constructor === null ?
            $class->newInstanceWithoutConstructor() :
            $class->newInstanceArgs(
                $this->parameterResolver->resolve(
                    $constructor,
                    $this->repository->classResource[$className]['constructor']['params'] ?? [],
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
    private function resolveMethod(ReflectionClass $class, string|bool $callMethod = null): void
    {
        $className = $class->getName();

        $this->repository->resolvedResource[$className]['returned'] = null;
        if ($callMethod === false) {
            return;
        }

        $method = $callMethod
            ?? $this->repository->classResource[$className]['method']['on']
            ?? ($class->getConstant('callOn') ?: $this->repository->defaultMethod);

        if (!empty($method) && $class->hasMethod($method)) {
            $method = new ReflectionMethod($className, $method);
            $this->repository->resolvedResource[$className]['returned'] = $method->invokeArgs(
                $this->repository->resolvedResource[$className]['instance'],
                $this->parameterResolver->resolve(
                    $method,
                    $this->repository->classResource[$className]['method']['params'] ?? [],
                    'method'
                )
            );
        }
    }
}
