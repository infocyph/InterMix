<?php

declare(strict_types=1);

namespace Infocyph\InterMix\DI\Resolver;

use Infocyph\InterMix\DI\Attribute\AttributeResolution;
use Infocyph\InterMix\DI\Attribute\Inject;
use Infocyph\InterMix\DI\Internal\ClassResolution;
use Infocyph\InterMix\Exceptions\ContainerException;
use Infocyph\InterMix\Internal\ReflectionResource;
use Psr\Cache\InvalidArgumentException;
use ReflectionClass;
use ReflectionException;
use ReflectionMethod;

class ClassResolver
{
    /** @var array<int, string> Tracing-only class ancestry. */
    private array $classStack = [];

    /** @var array<string, bool> */
    private array $entriesResolving = [];

    public function __construct(
        private readonly Repository $repository,
        private readonly ParameterResolver $parameterResolver,
        private readonly PropertyResolver $propertyResolver,
        private readonly DefinitionResolver $definitionResolver,
    ) {}

    /**
     * @param ReflectionClass<object> $class
     * @param array<int|string, mixed> $constructorParameters
     * @param array<int|string, mixed> $methodParameters
     */
    public function resolve(
        ReflectionClass $class,
        mixed $supplied = null,
        string|bool|null $callMethod = null,
        bool $make = false,
        array $constructorParameters = [],
        array $methodParameters = [],
    ): ClassResolution {
        $requestedClassName = $class->getName();
        $activated = $class->isInterface()
            && $this->repository->getEnvConcrete($requestedClassName) === null
            && $this->repository->hasMissingHooks()
                ? $this->resolveMissingInterface($requestedClassName)
                : null;
        if ($activated instanceof ClassResolution) {
            if (!is_string($callMethod) || $callMethod === '') {
                return $activated;
            }

            return new ClassResolution(
                $activated->instance,
                $this->invokeResolvedMethod(
                    $activated->instance::class,
                    $callMethod,
                    $activated->instance,
                    $methodParameters,
                ),
                true,
            );
        }

        $class = $this->getConcreteClassForInterface($class, $supplied);
        $className = $class->getName();
        $tracing = $this->repository->isTracingEnabled();
        if ($tracing) {
            $parent = end($this->classStack);
            if (is_string($parent) && $parent !== $className) {
                $this->repository->tracer()->recordDependency($parent, $className, 'class');
            }
            $this->classStack[] = $className;
            $this->repository->tracer()->push("class:$className");
        }

        try {
            $resolved = $make
                ? $this->resolveMake($class, $callMethod, $constructorParameters, $methodParameters)
                : $this->resolveClassResources(
                    $class,
                    $className,
                    $callMethod,
                    $constructorParameters,
                    $methodParameters,
                );
            $this->repository->markResolved($requestedClassName);
            $this->repository->markResolved($className);

            return $resolved;
        } finally {
            if ($tracing) {
                array_pop($this->classStack);
            }
        }
    }

    /** @param ReflectionClass<object> $class */
    public function resolveClassInstance(ReflectionClass $class): object
    {
        return $this->resolve($class)->instance;
    }

    public function resolveInject(Inject $inject): mixed
    {
        $typeData = $inject->getParameterData();
        if (!is_array($typeData)) {
            return AttributeResolution::Unresolved;
        }
        $type = $typeData['type'] ?? null;
        $data = $typeData['data'] ?? [];
        if (!is_string($type) || $type === '') {
            return AttributeResolution::Unresolved;
        }

        $fromDefinition = $this->resolveInjectFromDefinition($type);
        if ($fromDefinition !== AttributeResolution::Unresolved) {
            return $fromDefinition;
        }

        $fromFunction = $this->resolveInjectFromFunction($type, (array) $data);
        if ($fromFunction !== AttributeResolution::Unresolved) {
            return $fromFunction;
        }

        return $this->resolveInjectFromClassOrInterface($type);
    }

    /** @param ReflectionClass<object> $class */
    private function getConcreteClassForInterface(ReflectionClass $class, mixed $supplied): ReflectionClass
    {
        if (!$class->isInterface()) {
            return $class;
        }

        $className = $class->getName();
        $envConcrete = $this->repository->getEnvConcrete($className);
        if ($envConcrete !== null) {
            $concrete = ReflectionResource::getClassReflection($envConcrete);
            if (!$concrete->implementsInterface($className)) {
                throw new ContainerException("$envConcrete doesn't implement $className");
            }

            return $concrete;
        }

        if (!is_string($supplied) || !class_exists($supplied)) {
            $provided = is_scalar($supplied) || $supplied === null
                ? (string) $supplied
                : get_debug_type($supplied);
            throw new ContainerException("Resolution failed ($provided) for interface $className");
        }

        $concrete = ReflectionResource::getClassReflection($supplied);
        if (!$concrete->implementsInterface($className)) {
            throw new ContainerException("$supplied doesn't implement $className");
        }

        return $concrete;
    }

    /** @param array<int|string, mixed> $supplied */
    private function invokeResolvedMethod(
        string $className,
        string $method,
        object $instance,
        array $supplied = [],
    ): mixed {
        /** @var ReflectionMethod $refMethod */
        $refMethod = ReflectionResource::getCallableReflection([$className, $method]);

        return $refMethod->invokeArgs(
            $instance,
            $this->resolveMethodArguments($className, $refMethod, $supplied),
        );
    }

    private function readConfiguredMethod(string $className): ?string
    {
        $classRes = $this->repository->getClassResourceFor($className);
        $method = is_array($classRes['method'] ?? null)
            ? ($classRes['method']['on'] ?? null)
            : null;

        return is_string($method) ? $method : null;
    }

    /** @return array<int|string, mixed> */
    private function readConstructorParams(string $className): array
    {
        return $this->readResourceParams($className, 'constructor');
    }

    /** @return array<int|string, mixed> */
    private function readMethodParams(string $className): array
    {
        return $this->readResourceParams($className, 'method');
    }

    /** @return array<int|string, mixed> */
    private function readResourceParams(string $className, string $scope): array
    {
        $resource = $this->repository->getClassResourceFor($className)[$scope] ?? null;
        $params = is_array($resource) ? ($resource['params'] ?? []) : [];

        return is_array($params) ? $params : [];
    }

    /**
     * @param ReflectionClass<object> $class
     * @param array<int|string, mixed> $constructorParameters
     * @param array<int|string, mixed> $methodParameters
     */
    private function resolveClassResources(
        ReflectionClass $class,
        string $className,
        string|bool|null $callMethod,
        array $constructorParameters,
        array $methodParameters,
    ): ClassResolution {
        if (isset($this->entriesResolving[$className])) {
            throw new ContainerException("Circular dependency on {$className}");
        }
        $this->entriesResolving[$className] = true;

        try {
            $resolved = $this->repository->getResolvedResourceFor($className);
            if (!$resolved instanceof ClassResolution) {
                $instance = $this->resolveConstructor($class, $constructorParameters);
                $this->propertyResolver->resolve($class, $instance);
                $resolved = new ClassResolution($instance);
                $this->repository->setResolvedResource($className, $resolved);
            }

            return $this->resolveMethod($class, $callMethod, $resolved, $methodParameters);
        } finally {
            unset($this->entriesResolving[$className]);
        }
    }

    /** @param ReflectionClass<object> $class */
    private function resolveConfiguredTargetMethod(
        ReflectionClass $class,
        string $className,
        string|bool|null $callMethod,
    ): ?string {
        $constant = $class->hasConstant('CALL_ON') ? 'CALL_ON' : 'callOn';
        $callOn = $class->hasConstant($constant) ? $class->getConstant($constant) : null;
        $method = $callMethod
            ?: $this->readConfiguredMethod($className)
                ?: ($callOn ?: $this->repository->getDefaultMethod());

        if (!$method && $class->hasMethod('__invoke')) {
            $method = '__invoke';
        }

        return is_string($method) && $class->hasMethod($method) ? $method : null;
    }

    /** @param ReflectionClass<object> $class @param array<int|string, mixed> $supplied */
    private function resolveConstructor(ReflectionClass $class, array $supplied = []): object
    {
        $className = $class->getName();
        if (!$class->isInstantiable()) {
            throw new ContainerException("$className is not instantiable!");
        }

        $constructor = $class->getConstructor();
        if ($constructor === null) {
            return $class->newInstanceWithoutConstructor();
        }

        return $class->newInstanceArgs(
            $this->parameterResolver->resolve(
                $constructor,
                $supplied + $this->readConstructorParams($className),
                'constructor',
            ),
        );
    }

    private function resolveInjectFromClassOrInterface(string $type): mixed
    {
        if ($this->repository->hasFunctionReference($type)) {
            return $this->repository->container()->get($type);
        }

        if ($this->repository->hasMissingHooks()
            && !$this->repository->container()->has($type)
            && $this->repository->tryResolveMissing($type)
            && $this->repository->hasFunctionReference($type)
        ) {
            return $this->repository->container()->get($type);
        }

        if (!class_exists($type) && !interface_exists($type)) {
            return AttributeResolution::Unresolved;
        }

        if (interface_exists($type)) {
            $envConcrete = $this->repository->getEnvConcrete($type);
            if ($envConcrete !== null && class_exists($envConcrete)) {
                $type = $envConcrete;
            }
        }

        return $this->resolveClassInstance(ReflectionResource::getClassReflection($type));
    }

    private function resolveInjectFromDefinition(string $type): mixed
    {
        return $this->repository->hasFunctionReference($type)
            ? $this->repository->container()->get($type)
            : AttributeResolution::Unresolved;
    }

    /** @param array<int|string, mixed> $data */
    private function resolveInjectFromFunction(string $type, array $data): mixed
    {
        if (!function_exists($type)) {
            return AttributeResolution::Unresolved;
        }

        $reflectionFn = ReflectionResource::getFunctionReflection($type);

        return $type(...$this->parameterResolver->resolve($reflectionFn, $data, 'constructor'));
    }

    /**
     * @param ReflectionClass<object> $class
     * @param array<int|string, mixed> $constructorParameters
     * @param array<int|string, mixed> $methodParameters
     */
    private function resolveMake(
        ReflectionClass $class,
        string|bool|null $callMethod,
        array $constructorParameters,
        array $methodParameters,
    ): ClassResolution {
        $instance = $this->resolveConstructor($class, $constructorParameters);
        $this->propertyResolver->resolve($class, $instance);

        return $this->resolveMethod(
            $class,
            $callMethod,
            new ClassResolution($instance),
            $methodParameters,
        );
    }

    /**
     * @param ReflectionClass<object> $class
     * @param array<int|string, mixed> $methodParameters
     */
    private function resolveMethod(
        ReflectionClass $class,
        string|bool|null $callMethod,
        ClassResolution $resolved,
        array $methodParameters = [],
    ): ClassResolution {
        if ($callMethod === false) {
            return $resolved;
        }

        $className = $class->getName();
        $method = $this->resolveTargetMethod($class, $className, $callMethod);
        if ($method === null) {
            return $resolved;
        }

        return new ClassResolution(
            $resolved->instance,
            $this->invokeResolvedMethod($className, $method, $resolved->instance, $methodParameters),
            true,
        );
    }

    /** @param array<int|string, mixed> $supplied @return array<int|string, mixed> */
    private function resolveMethodArguments(
        string $className,
        ReflectionMethod $refMethod,
        array $supplied = [],
    ): array {
        return $this->parameterResolver->resolve(
            $refMethod,
            $supplied + $this->readMethodParams($className),
            'method',
        );
    }

    private function resolveMissingInterface(string $interface): ?ClassResolution
    {
        if (!$this->repository->tryResolveMissing($interface)
            || !$this->repository->hasFunctionReference($interface)
        ) {
            return null;
        }

        $resolved = $this->repository->container()->get($interface);
        if (!is_object($resolved) || !$resolved instanceof $interface) {
            throw new ContainerException("Resolved service doesn't implement $interface");
        }

        return new ClassResolution($resolved);
    }

    /** @param ReflectionClass<object> $class */
    private function resolveTargetMethod(
        ReflectionClass $class,
        string $className,
        string|bool|null $callMethod,
    ): ?string {
        if (is_string($callMethod) && $callMethod !== '') {
            if (!$class->hasMethod($callMethod)) {
                throw new ContainerException("Method {$className}::{$callMethod}() does not exist.");
            }

            return $callMethod;
        }

        return $this->resolveConfiguredTargetMethod($class, $className, $callMethod);
    }
}
