<?php

namespace AbmmHasan\OOF\DI\Resolver;

use AbmmHasan\OOF\Exceptions\ContainerException;
use ReflectionClass;
use ReflectionException;
use ReflectionMethod;

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
    public function getResolvedInstance(
        ReflectionClass $class,
        mixed $supplied = null,
        string|bool $callMethod = null
    ): array {
        $this->resolveClass($class, $supplied);
        $this->resolveMethod($class, $callMethod);

        return $this->repository->resolvedResource[$class->getName()];
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
                $this->parameterResolver->resolveParameters(
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
                $this->parameterResolver->resolveParameters(
                    $method,
                    $this->repository->classResource[$className]['method']['params'] ?? [],
                    'method'
                )
            );
        }
    }
}
