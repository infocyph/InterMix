<?php

declare(strict_types=1);

namespace Infocyph\InterMix\DI\Resolver;

use Infocyph\InterMix\DI\Attribute\IMStdClass;
use Infocyph\InterMix\DI\Attribute\Infuse;
use Infocyph\InterMix\DI\Reflection\ReflectionResource;
use Infocyph\InterMix\Exceptions\ContainerException;
use Psr\Cache\InvalidArgumentException;
use ReflectionClass;
use ReflectionException;
use ReflectionMethod;

class ClassResolver
{
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
        private readonly DefinitionResolver $definitionResolver
    ) {
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
        // 1) Extract the "type" (class name, function name, definition ID, etc.)
        $typeData = $infuse->getParameterData();
        // e.g. ['type' => 'SomeClassOrFunction', 'data' => [...]]

        $type = $typeData['type'] ?? null;
        $data = $typeData['data'] ?? [];  // extra data (array) if present

        // If no type, nothing to resolve
        if (! $type) {
            return new IMStdClass();
        }

        // 2) If $type is in functionReference => let definitionResolver handle it
        if ($this->repository->hasFunctionReference($type)) {
            return $this->definitionResolver->resolve($type);
        }

        // 3) If $type is a global function name
        if (function_exists($type)) {
            // Reflect the function to figure out parameters
            $reflectionFn = new \ReflectionFunction($type);
            // Use parameterResolver to handle injection or data
            $args = $this->parameterResolver->resolve($reflectionFn, (array) $data, 'constructor');

            // Call the function with resolved arguments
            return $type(...$args);
        }

        // 4) If $type is a class or interface => do a reflection-based resolution
        if (class_exists($type) || interface_exists($type)) {
            // (Optional) environment-based override if it's an interface
            if (interface_exists($type)) {
                $envConcrete = $this->repository->getEnvConcrete($type);
                if ($envConcrete && class_exists($envConcrete)) {
                    $type = $envConcrete;
                }
            }

            // Reflect & resolve using ClassResolver
            return $this->repository->fetchInstanceOrValue($this->resolve(ReflectionResource::getClassReflection($type)));
        }

        // 5) Otherwise, we have no way to resolve it
        return null;
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
     * @throws ContainerException|ReflectionException
     */
    public function resolve(
        ReflectionClass $class,
        mixed $supplied = null,
        string|bool|null $callMethod = null,
        bool $make = false
    ): array {
        // Possibly environment-based interface => check if $class->isInterface(), then environment override
        $class = $this->getConcreteClassForInterface($class, $supplied);
        $className = $class->getName();

        // If debug
        if ($this->repository->isDebug()) {
            // e.g. error_log("ClassResolver: resolving $className, make=$make");
        }

        return $make
            ? $this->resolveMake($class, $className, $callMethod)
            : $this->resolveClassResources($class, $className, $callMethod);
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
     * @throws ContainerException|ReflectionException
     */
    private function resolveMake(
        ReflectionClass $class,
        string $className,
        string|bool|null $callMethod
    ): array {
        $existing = $this->repository->getResolvedResource()[$className] ?? [];

        // build fresh
        $this->resolveConstructor($class);
        $this->propertyResolver->resolve($class);
        $this->resolveMethod($class, $callMethod);

        $newlyBuilt = $this->repository->getResolvedResource()[$className] ?? [];
        // revert the old
        $this->repository->setResolvedResource($className, $existing);

        return $newlyBuilt;
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
     * @throws ContainerException|ReflectionException
     */
    private function resolveClassResources(
        ReflectionClass $class,
        string $className,
        string|bool|null $callMethod
    ): array {
        if (isset($this->entriesResolving[$className])) {
            throw new ContainerException("Circular dependency on $className");
        }
        $this->entriesResolving[$className] = true;

        try {
            $resolvedResource = $this->repository->getResolvedResource()[$className] ?? [];

            if (! isset($resolvedResource['instance'])) {
                $this->resolveConstructor($class);
            }
            if (! isset($resolvedResource['property'])) {
                $this->propertyResolver->resolve($class);
            }
            $this->resolveMethod($class, $callMethod);

            return $this->repository->getResolvedResource()[$className] ?? [];
        } finally {
            unset($this->entriesResolving[$className]);
        }
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
        mixed $supplied
    ): ReflectionClass {
        if (! $class->isInterface()) {
            return $class;
        }
        $className = $class->getName();
        // environment override
        $envConcrete = $this->repository->getEnvConcrete($className);
        if ($envConcrete) {
            $class = ReflectionResource::getClassReflection($envConcrete);
            if (! $class->implementsInterface($className)) {
                throw new ContainerException("$envConcrete doesn't implement $className");
            }

            return $class;
        }
        // fallback to $supplied
        if (! $supplied || ! class_exists($supplied)) {
            throw new ContainerException("Resolution failed ($supplied) for interface $className");
        }
        $reflect = ReflectionResource::getClassReflection($supplied);
        if (! $reflect->implementsInterface($className)) {
            throw new ContainerException("$supplied doesn't implement $className");
        }

        return $reflect;
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
     * @throws ContainerException|ReflectionException If the class is not instantiable or if the constructor parameters cannot be resolved.
     */
    private function resolveConstructor(ReflectionClass $class): void
    {
        $className = $class->getName();
        if (! $class->isInstantiable()) {
            throw new ContainerException("$className is not instantiable!");
        }
        $constructor = $class->getConstructor();
        $resolvedResource = $this->repository->getResolvedResource()[$className] ?? [];

        if ($constructor === null) {
            $resolvedResource['instance'] = $class->newInstanceWithoutConstructor();
        } else {
            $classRes = $this->repository->getClassResource();
            $params = $classRes[$className]['constructor']['params'] ?? [];
            $args = $this->parameterResolver->resolve($constructor, $params, 'constructor');
            $resolvedResource['instance'] = $class->newInstanceArgs($args);
        }
        $this->repository->setResolvedResource($className, $resolvedResource);
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
     * @throws ReflectionException|ContainerException
     */
    private function resolveMethod(
        ReflectionClass $class,
        string|bool|null $callMethod
    ): void {
        $className = $class->getName();
        $resolvedResource = $this->repository->getResolvedResource()[$className] ?? [];
        $resolvedResource['returned'] = null;

        if ($callMethod === false) {
            $this->repository->setResolvedResource($className, $resolvedResource);

            return;
        }

        $method = $callMethod
            ?: ($this->repository->getClassResource()[$className]['method']['on'] ?? null)
                ?: ($class->getConstant('callOn') ?: $this->repository->getDefaultMethod());

        if (! $method && $class->hasMethod('__invoke')) {
            $method = '__invoke';
        }
        if (! $method || ! $class->hasMethod($method)) {
            $this->repository->setResolvedResource($className, $resolvedResource);

            return;
        }

        $refMethod = new ReflectionMethod($className, $method);
        $classRes = $this->repository->getClassResource();
        $params = $classRes[$className]['method']['params'] ?? [];
        $args = $this->parameterResolver->resolve($refMethod, $params, 'method');

        $returned = $refMethod->invokeArgs($resolvedResource['instance'], $args);
        $resolvedResource['returned'] = $returned;
        $this->repository->setResolvedResource($className, $resolvedResource);
    }
}
