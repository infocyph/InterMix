<?php

declare(strict_types=1);

namespace Infocyph\InterMix\DI\Resolver;

use Infocyph\InterMix\DI\Attribute\IMStdClass;
use Infocyph\InterMix\DI\Attribute\Infuse;
use Infocyph\InterMix\DI\Support\ReflectionResource;
use Infocyph\InterMix\Exceptions\ContainerException;
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
    ) {
    }

    /**
     * Resolve a class using the given ReflectionClass.
     *
     * First, possibly apply an environment-based override for interfaces.
     * Then, check if the class has already been resolved and stored in the repository.
     * If so, return the stored result.
     * If not, call one of the two methods below to resolve the class.
     *
     * @param ReflectionClass $class The class to resolve.
     * @param mixed $supplied The value to supply to the constructor, if applicable.
     * @param string|bool|null $callMethod The name of the method to call after instantiation, or true/false to call the constructor.
     * @param bool $make Whether to use the "make" method or the "resolveClassResources" method.
     * @return array An array containing the resolved instance and any returned value.
     * @throws ContainerException|ReflectionException|InvalidArgumentException
     */
    public function resolve(
        ReflectionClass $class,
        mixed $supplied = null,
        string|bool|null $callMethod = null,
        bool $make = false,
    ): array {
        // Possibly environment-based interface => check if $class->isInterface(), then environment override
        $class = $this->getConcreteClassForInterface($class, $supplied);
        $className = $class->getName();
        $parent = end($this->classStack);
        if (is_string($parent) && $parent !== $className) {
            $this->repository->tracer()->recordDependency($parent, $className, 'class');
        }

        $this->classStack[] = $className;
        $this->repository->tracer()->push("class:$className");

        try {
            return $make
                ? $this->resolveMake($class, $className, $callMethod)
                : $this->resolveClassResources($class, $className, $callMethod);
        } finally {
            array_pop($this->classStack);
        }
    }

    /**
     * Resolve an Infuse attribute by first extracting the "type" (class name, function name, definition ID, etc.)
     * and then trying to resolve it in the following order:
     * 1. If $type is in functionReference => let definitionResolver handle it
     * 2. If $type is a global function name => reflect the function and use parameterResolver to handle injection or data
     * 3. If $type is a class or interface => do a reflection-based resolution
     *    (optional) environment-based override if it's an interface
     * 4. Otherwise, we have no way to resolve it
     *
     * @param Infuse $infuse The Infuse attribute to resolve
     * @return mixed The resolved value or null if not possible
     * @throws ContainerException
     * @throws ReflectionException|InvalidArgumentException
     */
    public function resolveInfuse(Infuse $infuse): mixed
    {
        $typeData = $infuse->getParameterData();
        $type = $typeData['type'] ?? null;
        $data = $typeData['data'] ?? [];

        if (!$type) {
            return new IMStdClass();
        }

        $fromDefinition = $this->resolveInfuseFromDefinition($type);
        if ($fromDefinition !== null) {
            return $fromDefinition;
        }

        $fromFunction = $this->resolveInfuseFromFunction($type, (array)$data);
        if ($fromFunction !== null) {
            return $fromFunction;
        }

        return $this->resolveInfuseFromClassOrInterface($type);
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
     * @param ReflectionClass $class The interface class to resolve.
     * @param mixed $supplied The fallback class name to use if no environment override is found.
     * @return ReflectionClass The concrete class implementing the interface.
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
        if (!$supplied || !class_exists($supplied)) {
            throw new ContainerException("Resolution failed ($supplied) for interface $className");
        }
        $reflect = ReflectionResource::getClassReflection($supplied);
        if (!$reflect->implementsInterface($className)) {
            throw new ContainerException("$supplied doesn't implement $className");
        }

        return $reflect;
    }

    private function initializeMethodResolutionState(string $className): array
    {
        $resolvedResource = $this->repository->getResolvedResourceFor($className);
        $resolvedResource['returned'] = null;
        return $resolvedResource;
    }

    private function invokeResolvedMethod(string $className, string $method, object $instance): mixed
    {
        /** @var ReflectionMethod $refMethod */
        $refMethod = ReflectionResource::getCallableReflection([$className, $method]);
        $args = $this->resolveMethodArguments($className, $refMethod);
        return $refMethod->invokeArgs($instance, $args);
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
     * @param ReflectionClass $class The class to resolve.
     * @param string $className The name of the class.
     * @param string|bool|null $callMethod The name of the method to call after instantiation, or true/false to call the constructor.
     * @return array An array containing the resolved instance and any returned value.
     * @throws ContainerException|ReflectionException|InvalidArgumentException
     */
    private function resolveClassResources(
        ReflectionClass $class,
        string $className,
        string|bool|null $callMethod,
    ): array {
        if (isset($this->entriesResolving[$className])) {
            throw new ContainerException("Circular dependency on {$className}");
        }
        $this->entriesResolving[$className] = true;

        try {
            $resolvedResource = $this->repository->getResolvedResourceFor($className);

            if (!isset($resolvedResource['instance'])) {
                $this->resolveConstructor($class);
            }
            if (!isset($resolvedResource['property'])) {
                $this->propertyResolver->resolve($class);
            }
            $this->resolveMethod($class, $callMethod);

            return $this->repository->getResolvedResourceFor($className);
        } finally {
            unset($this->entriesResolving[$className]);
        }
    }

    /**
     * Resolve the constructor of a class.
     *
     * If the class has no constructor, an instance is created with
     * {@see ReflectionClass::newInstanceWithoutConstructor()}.
     * If the class has a constructor, the constructor parameters are resolved
     * using the {@see ParameterResolver} and an instance is created with
     * {@see ReflectionClass::newInstanceArgs()}.
     *
     * The resolved instance is stored in the {@see Repository} under the
     * key `resolvedResource[$className]['instance']`.
     *
     * @param ReflectionClass $class The class to resolve the constructor for.
     * @throws ContainerException|ReflectionException|InvalidArgumentException If the class is not instantiable or if the constructor parameters cannot be resolved.
     */
    private function resolveConstructor(ReflectionClass $class): void
    {
        $className = $class->getName();
        if (!$class->isInstantiable()) {
            throw new ContainerException("$className is not instantiable!");
        }
        $constructor = $class->getConstructor();
        $resolvedResource = $this->repository->getResolvedResourceFor($className);

        if ($constructor === null) {
            $resolvedResource['instance'] = $class->newInstanceWithoutConstructor();
        } else {
            $classRes = $this->repository->getClassResourceFor($className);
            $params = $classRes['constructor']['params'] ?? [];
            $args = $this->parameterResolver->resolve($constructor, $params, 'constructor');
            $resolvedResource['instance'] = $class->newInstanceArgs($args);
        }
        $this->repository->setResolvedResource($className, $resolvedResource);
    }

    private function resolveInfuseFromClassOrInterface(string $type): mixed
    {
        if (!class_exists($type) && !interface_exists($type)) {
            return null;
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

    private function resolveInfuseFromDefinition(string $type): mixed
    {
        return $this->repository->hasFunctionReference($type)
            ? $this->definitionResolver->resolve($type)
            : null;
    }

    /**
     * @param array<int|string, mixed> $data
     */
    private function resolveInfuseFromFunction(string $type, array $data): mixed
    {
        if (!function_exists($type)) {
            return null;
        }

        $reflectionFn = new \ReflectionFunction($type);
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
     * @param ReflectionClass $class The class to resolve.
     * @param string $className The name of the class.
     * @param string|bool|null $callMethod The name of the method to call after instantiation, or true/false to call the constructor.
     * @return array An array containing the resolved instance and any returned value.
     * @throws ContainerException|ReflectionException|InvalidArgumentException
     */
    private function resolveMake(
        ReflectionClass $class,
        string $className,
        string|bool|null $callMethod,
    ): array {
        $existing = $this->repository->getResolvedResourceFor($className);

        // build fresh
        $this->resolveConstructor($class);
        $this->propertyResolver->resolve($class);
        $this->resolveMethod($class, $callMethod);

        $newlyBuilt = $this->repository->getResolvedResourceFor($className);
        // revert the old
        $this->repository->setResolvedResource($className, $existing);

        return $newlyBuilt;
    }

    /**
     * Resolves a method to be called on the instance of the class.
     *
     * The method to be called can be specified as a string, or as a boolean
     * value to indicate whether to call the constructor or not.
     *
     * If the method is not specified, the method name will be looked up in the
     * class resource, or in the class constant "callOn", or in the default
     * method name stored in the repository.
     *
     * If the method does not exist, the returned value will be null.
     *
     * The resolved instance and the returned value are stored in the
     * repository under the key "resolvedResource[$className]".
     *
     * @param ReflectionClass $class The class to resolve the method for.
     * @param string|bool|null $callMethod The name of the method to call, or a boolean value to indicate whether to call the constructor or not.
     * @throws ReflectionException|ContainerException|InvalidArgumentException
     */
    private function resolveMethod(
        ReflectionClass $class,
        string|bool|null $callMethod,
    ): void {
        $className = $class->getName();
        $resolvedResource = $this->initializeMethodResolutionState($className);
        if ($callMethod === false) {
            $this->repository->setResolvedResource($className, $resolvedResource);
            return;
        }

        $method = $this->resolveTargetMethod($class, $className, $callMethod);
        if ($method === null) {
            $this->repository->setResolvedResource($className, $resolvedResource);
            return;
        }

        $resolvedResource['returned'] = $this->invokeResolvedMethod($className, $method, $resolvedResource['instance']);
        $this->repository->setResolvedResource($className, $resolvedResource);
    }

    private function resolveMethodArguments(string $className, ReflectionMethod $refMethod): array
    {
        $classRes = $this->repository->getClassResourceFor($className);
        $params = $classRes['method']['params'] ?? [];
        return $this->parameterResolver->resolve($refMethod, $params, 'method');
    }

    private function resolveTargetMethod(
        ReflectionClass $class,
        string $className,
        string|bool|null $callMethod,
    ): ?string {
        $callOn = $class->hasConstant('callOn') ? $class->getConstant('callOn') : null;
        $classRes = $this->repository->getClassResourceFor($className);
        $method = $callMethod
            ?: ($classRes['method']['on'] ?? null)
                ?: ($callOn ?: $this->repository->getDefaultMethod());

        if (!$method && $class->hasMethod('__invoke')) {
            $method = '__invoke';
        }

        return is_string($method) && $class->hasMethod($method) ? $method : null;
    }
}
