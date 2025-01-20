<?php

declare(strict_types=1);

namespace Infocyph\InterMix\DI\Resolver;

use Infocyph\InterMix\DI\Attribute\Infuse;
use Infocyph\InterMix\DI\Reflection\ReflectionResource;
use Infocyph\InterMix\Exceptions\ContainerException;
use ReflectionClass;
use ReflectionNamedType;
use ReflectionProperty;
use ReflectionType;

class PropertyResolver
{
    private ClassResolver $classResolver;

    public function __construct(
        private Repository $repository,
        private readonly ParameterResolver $parameterResolver
    ) {
    }

    public function setClassResolverInstance(ClassResolver $classResolver): void
    {
        $this->classResolver = $classResolver;
    }

    /**
     * Resolves properties of a given class (and parent private props).
     */
    public function resolve(ReflectionClass $class): void
    {
        $className = $class->getName();
        $allResolved = $this->repository->getResolvedResource()[$className] ?? [];
        if (! isset($allResolved['instance'])) {
            return; // no instance => no property injection
        }

        $instance = $allResolved['instance'];

        // Possibly debug
        if ($this->repository->isDebug()) {
            // e.g. error_log("PropertyResolver: injecting properties for $className");
        }

        $this->processProperties($class, $class->getProperties(), $instance);

        if ($parentClass = $class->getParentClass()) {
            // handle parent private props
            $this->processProperties(
                $parentClass,
                $parentClass->getProperties(ReflectionProperty::IS_PRIVATE),
                $instance
            );
        }

        $allResolved['property'] = true;
        $this->repository->setResolvedResource($className, $allResolved);
    }

    private function processProperties(
        ReflectionClass $class,
        array $properties,
        object $classInstance
    ): void {
        if (! $properties) {
            return;
        }
        $className = $class->getName();
        $classResource = $this->repository->getClassResource();
        $registeredProps = $classResource[$className]['property'] ?? null;

        if ($registeredProps === null && ! $this->repository->isPropertyAttributeEnabled()) {
            return;
        }

        foreach ($properties as $property) {
            if ($property->isPromoted()) {
                continue; // skip promoted
            }
            $values = $this->resolveValue($property, $registeredProps ?? [], $classInstance);
            if ($values) {
                $property->setValue(...$values);
            }
        }
    }

    private function resolveValue(
        ReflectionProperty $property,
        array $classPropertyValues,
        object $classInstance
    ): ?array {
        $propName = $property->getName();

        // 1) check if user-supplied
        $predefined = $this->setWithPredefined($property, $classPropertyValues, $classInstance);
        if ($predefined !== null) {
            return $predefined; // could be [] or [obj, val]
        }

        // 2) attribute-based
        if (! $this->repository->isPropertyAttributeEnabled()) {
            return [];
        }
        $attr = $property->getAttributes(Infuse::class);
        if (! $attr) {
            return [];
        }

        $parameterType = $property->getType();
        $infuse = $attr[0]->newInstance();
        if (empty($attr[0]->getArguments())) {
            // no arguments => reflect property type
            return [
                $classInstance,
                $this->resolveWithoutArgument($property, $parameterType),
            ];
        }

        // otherwise pass to classResolver->resolveInfuse
        $resolved = $this->classResolver->resolveInfuse($infuse);
        if ($resolved === null) {
            throw new ContainerException(
                "Unknown #[Infuse] property on {$property->getDeclaringClass()->getName()}::\$$propName"
            );
        }

        return [$classInstance, $resolved];
    }

    private function setWithPredefined(
        ReflectionProperty $property,
        array $classPropertyValues,
        object $classInstance
    ): ?array {
        $propName = $property->getName();
        if ($property->isStatic() && isset($classPropertyValues[$propName])) {
            return [$classPropertyValues[$propName]];
        }
        if (isset($classPropertyValues[$propName])) {
            return [$classInstance, $classPropertyValues[$propName]];
        }
        if (! $this->repository->isPropertyAttributeEnabled()) {
            return [];
        }

        return null;
    }

    private function resolveWithoutArgument(
        ReflectionProperty $property,
        ?ReflectionType $parameterType
    ): object {
        if (! $parameterType instanceof ReflectionNamedType || $parameterType->isBuiltin()) {
            throw new ContainerException(
                'Malformed #[Infuse] or invalid property type on '.
                "{$property->getDeclaringClass()->getName()}::\${$property->getName()}"
            );
        }
        // environment-based override if interface
        $className = $parameterType->getName();
        if (interface_exists($className)) {
            $envConcrete = $this->repository->getEnvConcrete($className);
            $className = $envConcrete ?: $className;
        }
        $refClass = ReflectionResource::getClassReflection($className);

        return $this->classResolver->resolve($refClass)['instance'];
    }
}
