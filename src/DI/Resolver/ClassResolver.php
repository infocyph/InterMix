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

/**
 * Handles class resolution with dependency injection and attribute processing.
 *
 * This resolver is responsible for creating class instances with full dependency
 * injection support. It handles constructor injection, property injection,
 * method injection, and attribute-based configuration.
 *
 * Features:
 * - Recursive dependency resolution with circular reference detection
 * - Environment-based interface binding
 * - Attribute processing for injection configuration
 * - Support for singleton, transient, and scoped lifetimes
 */
class ClassResolver
{
    /** @var array<int, string> Stack for tracking class resolution depth to prevent infinite recursion */
    private array $classStack = [];

    /** @var array<string, bool> Tracks which entries are currently being resolved */
    private array $entriesResolving = [];

    /**
     * Constructs a ClassResolver instance.
     *
     * @param Repository $repository The Repository providing definitions, classes, functions, and parameters.
     * @param ParameterResolver $parameterResolver The ParameterResolver resolving function/method parameters.
     * @param PropertyResolver $propertyResolver The PropertyResolver resolving class properties.
     * @param DefinitionResolver $definitionResolver The DefinitionResolver resolving definitions.
     */
    public function __construct(
        private readonly Repository $repository,
        private readonly ParameterResolver $parameterResolver,
        private readonly PropertyResolver $propertyResolver,
        private readonly DefinitionResolver $definitionResolver,
    ) {}

    /**
     * Resolve a class using the given ReflectionClass.
     *
     * First, possibly apply an environment-based override for interfaces.
     * Then, check if the class has already been resolved and stored in the repository.
     * If so, return the stored result.
     * If not, call one of the two methods below to resolve the class.
     *
     * @param ReflectionClass<object> $class The class to resolve.
     * @param mixed $supplied The value to supply to the constructor, if applicable.
     * @param string|bool|null $callMethod The name of the method to call after instantiation, or true/false to call the constructor.
     * @param bool $make Whether to use the "make" method or the "resolveClassResources" method.
     * @param array<int|string, mixed> $constructorParameters Ephemeral constructor arguments.
     * @param array<int|string, mixed> $methodParameters Ephemeral method arguments.
     * @throws ContainerException|ReflectionException|InvalidArgumentException
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
        $parent = end($this->classStack);
        if (is_string($parent) && $parent !== $className && $this->repository->isTracingEnabled()) {
            $this->repository->tracer()->recordDependency($parent, $className, 'class');
        }

        $this->classStack[] = $className;
        if ($this->repository->isTracingEnabled()) {
            $this->repository->tracer()->push("class:$className");
        }

        try {
            $resolved = $make
                ? $this->resolveMake($class, $callMethod, $constructorParameters, $methodParameters)
                : $this->resolveClassResources($class, $className, $callMethod, $constructorParameters, $methodParameters);
            $this->repository->markResolved($requestedClassName);
            $this->repository->markResolved($className);

            return $resolved;
        } finally {
            array_pop($this->classStack);
        }
    }

    /**
     * Resolve a class and return its concrete object instance.
     *
     * @param ReflectionClass<object> $class
     *
     * @throws ContainerException|ReflectionException|InvalidArgumentException
     */
    public function resolveClassInstance(ReflectionClass $class): object
    {
        return $this->resolve($class)->instance;
    }

    /**
     * Resolve an Inject attribute by first extracting the "type" (class name, function name, definition ID, etc.)
     * and then trying to resolve it in the following order:
     * 1. If $type is in functionReference => let definitionResolver handle it
     * 2. If $type is a global function name => reflect the function and use parameterResolver to handle injection or data
     * 3. If $type is a class or interface => do a reflection-based resolution
     *    (optional) environment-based override if it's an interface
     * 4. Otherwise, we have no way to resolve it
     *
     * @param Inject $inject The Inject attribute to resolve
     * @return mixed The resolved value or the unresolved sentinel
     *
     * @throws ContainerException
     * @throws ReflectionException|InvalidArgumentException
     */
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

    /**
     * Resolves a concrete class for a given interface.
     *
     * This method checks if the provided class is an interface and attempts
     * to find a concrete implementation. First, it checks for an environment-based
     * override. If found, it verifies that the concrete class implements the interface.
     * If no environment override is found, it falls back to a supplied class name,
     * throwing an exception if the supplied class does not exist or does not implement
     * the required interface.
     *
     * @param ReflectionClass<object> $class The interface class to resolve.
     * @param mixed $supplied The fallback class name to use if no environment override is found.
     * @return ReflectionClass<object> The concrete class implementing the interface.
     *
     * @throws ContainerException|ReflectionException If no valid concrete class is found or if it does not implement the interface.
     */
    private function getConcreteClassForInterface(
        ReflectionClass $class,
        mixed $supplied,
    ): ReflectionClass {
        if (!$class->isInterface()) {
            return $class;
        }
        $className = $class->getName();
        $envConcrete = $this->repository->getEnvConcrete($className);
        if ($envConcrete) {
            $class = ReflectionResource::getClassReflection($envConcrete);
            if (!$class->implementsInterface($className)) {
                throw new ContainerException("$envConcrete doesn't implement $className");
            }

            return $class;
        }
        // fallback to $supplied
        if (!is_string($supplied) || !class_exists($supplied)) {
            $provided = is_scalar($supplied) || $supplied === null ? (string) $supplied : get_debug_type($supplied);

            throw new ContainerException("Resolution failed ($provided) for interface $className");
        }
        $reflect = ReflectionResource::getClassReflection($supplied);
        if (!$reflect->implementsInterface($className)) {
            throw new ContainerException("$supplied doesn't implement $className");
        }

        return $reflect;
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
        $args = $this->resolveMethodArguments($className, $refMethod, $supplied);

        return $refMethod->invokeArgs($instance, $args);
    }

    private function readConfiguredMethod(string $className): ?string
    {
        $classRes = $this->repository->getClassResourceFor($className);
        if (!isset($classRes['method']) || !is_array($classRes['method'])) {
            return null;
        }
        $method = $classRes['method']['on'] ?? null;

        return is_string($method) ? $method : null;
    }

    /**
     * @return array<int|string, mixed>
     */
    private function readConstructorParams(string $className): array
    {
        return $this->readResourceParams($className, 'constructor');
    }

    /**
     * @return array<int|string, mixed>
     */
    private function readMethodParams(string $className): array
    {
        return $this->readResourceParams($className, 'method');
    }

    /**
     * @return array<int|string, mixed>
     */
    private function readResourceParams(string $className, string $scope): array
    {
        $classRes = $this->repository->getClassResourceFor($className);
        if (!isset($classRes[$scope]) || !is_array($classRes[$scope])) {
            return [];
        }
        $params = $classRes[$scope]['params'] ?? [];

        return is_array($params) ? $params : [];
    }

    /**
     * Resolve a class using the given ReflectionClass and store the result in the repository.
     *
     * This method is used by the "resolve" method, which uses the repository to cache the results.
     *
     * First, resolve the class's constructor.
     * Then, resolve any properties.
     * Finally, call the method on the class if applicable.
     *
     * The newly built result is returned, and the repository is updated.
     *
     * @param ReflectionClass<object> $class The class to resolve.
     * @param string|bool|null $callMethod The name of the method to call after instantiation, or true/false to call the constructor.
     * @throws ContainerException|ReflectionException|InvalidArgumentException
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

    /**
     * @param ReflectionClass<object> $class
     */
    private function resolveConfiguredTargetMethod(
        ReflectionClass $class,
        string $className,
        string|bool|null $callMethod,
    ): ?string {
        $constant = $class->hasConstant('CALL_ON') ? 'CALL_ON' : 'callOn';
        $callOn = $class->hasConstant($constant) ? $class->getConstant($constant) : null;
        $configuredMethod = $this->readConfiguredMethod($className);
        $method = $callMethod
            ?: $configuredMethod
                ?: ($callOn ?: $this->repository->getDefaultMethod());

        if (!$method && $class->hasMethod('__invoke')) {
            $method = '__invoke';
        }

        return is_string($method) && $class->hasMethod($method) ? $method : null;
    }

    /**
     * Resolve the constructor of a class.
     *
     * Constructor parameters are resolved when present; otherwise an instance
     * is created without constructor arguments. The repository retains the
     * resulting instance for the active resolution lifecycle.
     *
     * @param ReflectionClass<object> $class The class to resolve the constructor for.
     *
     * @throws ContainerException|ReflectionException|InvalidArgumentException If the class is not instantiable or if the constructor parameters cannot be resolved.
     */
    /**
     * @param ReflectionClass<object> $class
     * @param array<int|string, mixed> $supplied
     */
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

        $params = $supplied + $this->readConstructorParams($className);
        $args = $this->parameterResolver->resolve($constructor, $params, 'constructor');

        return $class->newInstanceArgs($args);
    }

    private function resolveInjectFromClassOrInterface(string $type): mixed
    {
        if ($this->repository->hasFunctionReference($type)) {
            return $this->definitionResolver->resolve($type);
        }

        if (!$this->repository->container()->has($type)
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
            if ($envConcrete && class_exists($envConcrete)) {
                $type = $envConcrete;
            }
        }

        return $this->repository->fetchInstanceOrValue(
            $this->resolve(ReflectionResource::getClassReflection($type)),
        );
    }

    private function resolveInjectFromDefinition(string $type): mixed
    {
        return $this->repository->hasFunctionReference($type)
            ? $this->definitionResolver->resolve($type)
            : AttributeResolution::Unresolved;
    }

    /**
     * @param array<int|string, mixed> $data
     */
    private function resolveInjectFromFunction(string $type, array $data): mixed
    {
        if (!function_exists($type)) {
            return AttributeResolution::Unresolved;
        }

        $reflectionFn = ReflectionResource::getFunctionReflection($type);
        $args = $this->parameterResolver->resolve($reflectionFn, $data, 'constructor');

        return $type(...$args);
    }

    /**
     * Resolve a class using the given ReflectionClass.
     *
     * This method is used by the "make" method, which bypasses the repository and resolves the class
     * from scratch.
     *
     * First, resolve the class's constructor.
     * Then, resolve any properties.
     * Finally, call the method on the class if applicable.
     *
     * The newly built result is returned, and the repository is reverted to its previous state.
     *
     * @param ReflectionClass<object> $class The class to resolve.
     * @param string|bool|null $callMethod The name of the method to call after instantiation, or true/false to call the constructor.
     * @throws ContainerException|ReflectionException|InvalidArgumentException
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

        return $this->resolveMethod($class, $callMethod, new ClassResolution($instance), $methodParameters);
    }

    /**
     * Resolves a method to be called on the instance of the class.
     *
     * Explicit selection takes precedence over registered class resources and
     * the repository default. An explicitly selected missing method throws;
     * absent implicit configuration leaves the instance uninvoked.
     *
     * @param ReflectionClass<object> $class The class to resolve the method for.
     * @param string|bool|null $callMethod The name of the method to call, or a boolean value to indicate whether to call the constructor or not.
     *
     * @throws ReflectionException|ContainerException|InvalidArgumentException
     * @param array<int|string, mixed> $methodParameters
     */
    private function resolveMethod(
        ReflectionClass $class,
        string|bool|null $callMethod,
        ClassResolution $resolved,
        array $methodParameters = [],
    ): ClassResolution {
        $className = $class->getName();
        if ($callMethod === false) {
            return new ClassResolution($resolved->instance);
        }

        $method = $this->resolveTargetMethod($class, $className, $callMethod);
        if ($method === null) {
            return new ClassResolution($resolved->instance);
        }

        return new ClassResolution(
            $resolved->instance,
            $this->invokeResolvedMethod($className, $method, $resolved->instance, $methodParameters),
            true,
        );
    }

    /**
     * @return array<int|string, mixed>
     */
    /**
     * @param array<int|string, mixed> $supplied
     * @return array<int|string, mixed>
     */
    private function resolveMethodArguments(string $className, ReflectionMethod $refMethod, array $supplied = []): array
    {
        $params = $supplied + $this->readMethodParams($className);

        return $this->parameterResolver->resolve($refMethod, $params, 'method');
    }

    private function resolveMissingInterface(string $interface): ?ClassResolution
    {
        if (!$this->repository->tryResolveMissing($interface)
            || !$this->repository->hasFunctionReference($interface)) {
            return null;
        }

        $resolved = $this->repository->container()->get($interface);
        if (!is_object($resolved) || !$resolved instanceof $interface) {
            throw new ContainerException("Resolved service doesn't implement $interface");
        }

        return new ClassResolution($resolved);
    }

    /**
     * @param ReflectionClass<object> $class
     */
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
