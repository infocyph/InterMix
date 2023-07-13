<?php

namespace AbmmHasan\InterMix\DI\Resolver;

use AbmmHasan\InterMix\DI\Attribute\Infuse;
use AbmmHasan\InterMix\Exceptions\ContainerException;
use ReflectionClass;
use ReflectionException;
use ReflectionFunction;
use ReflectionMethod;

class ClassResolver
{
    use Reflector;

    /**
     * @param Repository $repository
     * @param ParameterResolver $parameterResolver
     * @param PropertyResolver $propertyResolver
     */
    public function __construct(
        private Repository $repository,
        private ParameterResolver $parameterResolver,
        private PropertyResolver $propertyResolver
    ) {
    }

    /**
     * Resolve attribute via Infuse
     *
     * @param Infuse $infuse
     * @return mixed
     * @throws ContainerException
     * @throws ReflectionException
     */
    public function resolveInfuse(Infuse $infuse): mixed
    {
        $type = $infuse->getNonMethodData('type');

        if (array_key_exists($type, $this->repository->functionReference)) {
            return $this->parameterResolver->prepareDefinition($type);
        }

        if (function_exists($type)) {
            return $type(
                ...
                $this->parameterResolver->resolve(
                    new ReflectionFunction($type),
                    (array)$infuse->getNonMethodData('data'),
                    'constructor'
                )
            );
        }

        return null;
    }

    /**
     * Get resolved Instance & method
     *
     * @param ReflectionClass $class
     * @param mixed|null $supplied
     * @param string|bool|null $callMethod
     * @param bool $make
     * @return array
     * @throws ContainerException
     * @throws ReflectionException
     */
    public function resolve(
        ReflectionClass $class,
        mixed $supplied = null,
        string|bool $callMethod = null,
        bool $make = false
    ): array {
        $class = $this->getClass($class, $supplied);
        $className = $class->getName();
        if ($make) {
            return $this->resolveMake($class, $className, $callMethod);
        }
        $this->resolveClassResources($class, $className, $callMethod);
        return $this->repository->resolvedResource[$className];
    }

    /**
     * Rebuild class for make
     *
     * @param ReflectionClass $class
     * @param string $className
     * @param string|bool|null $callMethod
     * @return array
     * @throws ReflectionException|ContainerException
     */
    private function resolveMake(ReflectionClass $class, string $className, string|bool|null $callMethod): array
    {
        $existing = $this->repository->resolvedResource[$className] ?? [];
        $this->resolveConstructor($class);
        $this->propertyResolver->resolve($class);
        $this->resolveMethod($class, $callMethod);
        $resolved = $this->repository->resolvedResource[$className];
        $this->repository->resolvedResource[$className] = $existing;
        return $resolved;
    }

    /**
     * Resolve class resources
     *
     * @param ReflectionClass $class
     * @param string $className
     * @param string|bool|null $callMethod
     * @return void
     * @throws ReflectionException|ContainerException
     */
    private function resolveClassResources(
        ReflectionClass $class,
        string $className,
        string|bool|null $callMethod
    ): void {
        if (!isset($this->repository->resolvedResource[$className]['instance'])) {
            $this->resolveConstructor($class);
        }

        if (!isset($this->repository->resolvedResource[$className]['property'])) {
            $this->propertyResolver->resolve($class);
        }

        $this->resolveMethod($class, $callMethod);
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
            if (!$supplied || !class_exists($supplied)) {
                throw new ContainerException("Resolution failed $supplied for interface $className");
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
     * Resolve class (initiate & construct)
     *
     * @param ReflectionClass $class
     * @return void
     * @throws ContainerException|ReflectionException
     */
    private function resolveConstructor(ReflectionClass $class): void
    {
        $className = $class->getName();
        if (!$class->isInstantiable()) {
            throw new ContainerException("$className is not instantiable!");
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
            ?: $this->repository->classResource[$className]['method']['on']
                ??= $class->getConstant('callOn')
                ?: $this->repository->defaultMethod;

        $method = match (true) {
            $method && $class->hasMethod($method) => $method,
            $class->hasMethod('__invoke') => '__invoke',
            default => false
        };

        if (!$method) {
            return;
        }

        $reflectedMethod = new ReflectionMethod($className, $method);
        $this->repository->resolvedResource[$className]['returned'] = $reflectedMethod->invokeArgs(
            $this->repository->resolvedResource[$className]['instance'],
            $this->parameterResolver->resolve(
                $reflectedMethod,
                $this->repository->classResource[$className]['method']['params'] ?? [],
                'method'
            )
        );
    }
}
