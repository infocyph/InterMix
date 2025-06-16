<?php

declare(strict_types=1);

namespace Infocyph\InterMix\DI\Resolver;

use Infocyph\InterMix\DI\Attribute\IMStdClass;
use Infocyph\InterMix\DI\Attribute\Infuse;
use Infocyph\InterMix\DI\Reflection\ReflectionResource;
use Infocyph\InterMix\DI\Reflection\TraceLevel;
use Infocyph\InterMix\Exceptions\ContainerException;
use Psr\Cache\InvalidArgumentException;
use ReflectionClass;
use ReflectionException;
use ReflectionNamedType;
use ReflectionProperty;
use ReflectionType;

class PropertyResolver
{
    private ClassResolver $classResolver;

    /**
     * Constructs a PropertyResolver instance.
     *
     * @param Repository $repository The Repository providing definitions, classes, functions, and parameters.
     */
    public function __construct(
        private readonly Repository $repository,
    ) {
    }

    /**
     * Called by Container to switch between InjectedCall & GenericCall, etc.
     *
     * @param ClassResolver $classResolver The new ClassResolver instance.
     */
    public function setClassResolverInstance(ClassResolver $classResolver): void
    {
        $this->classResolver = $classResolver;
    }


    /**
     * Resolve any properties for the given class (if instance is already resolved).
     * If no instance, does nothing.
     *
     * First, resolve any public properties of the class.
     * Then, resolve any private properties of the parent class.
     * Finally, mark the property resolution as complete in the repository.
     *
     * @param ReflectionClass $class The class to resolve properties for.
     * @throws ContainerException|ReflectionException
     */
    public function resolve(ReflectionClass $class): void
    {
        $className = $class->getName();
        $allResolved = $this->repository->getResolvedResource()[$className] ?? [];
        if (!isset($allResolved['instance'])) {
            return; // no instance => no property injection
        }

        $instance = $allResolved['instance'];

        $this->processProperties($class, $class->getProperties(), $instance);

        if ($parentClass = $class->getParentClass()) {
            // handle parent private props
            $this->processProperties(
                $parentClass,
                $parentClass->getProperties(ReflectionProperty::IS_PRIVATE),
                $instance,
            );
        }

        $allResolved['property'] = true;
        $this->repository->setResolvedResource($className, $allResolved);
    }

    /**
     * Resolves any properties for the given class and instance.
     * Skips properties already set.
     *
     * @param ReflectionClass $class The class to resolve properties for.
     * @param array $properties The properties to resolve.
     * @param object $classInstance The instance of the class to set properties on.
     * @throws ContainerException|ReflectionException|InvalidArgumentException
     */
    private function processProperties(
        ReflectionClass $class,
        array $properties,
        object $classInstance,
    ): void {
        if (!$properties) {
            return;
        }
        $className = $class->getName();
        $classResource = $this->repository->getClassResource();
        $registeredProps = $classResource[$className]['property'] ?? null;

        if ($registeredProps === null && !$this->repository->isPropertyAttributeEnabled()) {
            return;
        }

        /** @var ReflectionProperty $property */
        foreach ($properties as $property) {
            if ($property->isPromoted()
                && !isset(($registeredProps ?? [])[$property->getName()])
                && empty($property->getAttributes(Infuse::class))
            ) {
                continue;
            }
            $this->repository->tracer()->push(
                "prop {$property->getName()} of $className",
                TraceLevel::Verbose
            );
            $values = $this->resolveValue($property, $registeredProps ?? [], $classInstance);
            if ($values) {
                match (true) {
                    $property->isStatic() => $class->setStaticPropertyValue($property->getName(), $values[0]),
                    default => $property->setValue($values[0], $values[1]),
                };
            }
            $this->repository->tracer()->pop();
        }
    }

    /**
     * Resolve a single property value.
     *
     * 1) User-supplied values have priority.
     * 2) If not user-supplied, then attributes are checked.
     * 3) If no attribute, then return an empty array.
     *
     * @param ReflectionProperty $property The property to resolve a value for.
     * @param array $classPropertyValues The user-supplied values for the class.
     * @param object $classInstance The instance of the class to set the property on.
     * @return ?array An array of two items: the instance and the resolved value. Or null if not possible to resolve.
     * @throws ContainerException|ReflectionException|InvalidArgumentException
     */
    private function resolveValue(
        ReflectionProperty $property,
        array $classPropertyValues,
        object $classInstance,
    ): ?array {
        $propName = $property->getName();

        // 1) check if user-supplied
        $predefined = $this->setWithPredefined($property, $classPropertyValues, $classInstance);
        if ($predefined !== null) {
            return $predefined; // could be [] or [obj, val]
        }

        // 2) attribute-based
        if (!$this->repository->isPropertyAttributeEnabled()) {
            return [];
        }
        $attr = $property->getAttributes(Infuse::class);
        if (!$attr) {
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
        if ($resolved === new IMStdClass()) {
            throw new ContainerException(
                "Unknown #[Infuse] property on {$property->getDeclaringClass()->getName()}::\$$propName",
            );
        }

        return [$classInstance, $resolved];
    }

    /**
     * Try to set a value for a property based on predefined values.
     *
     * Checks if a value is set in the predefined $classPropertyValues array
     * and if so, returns it. If not, and attribute-based property resolution is
     * enabled, returns null so that the attribute-based approach can be used.
     *
     * @param ReflectionProperty $property The property to set.
     * @param array $classPropertyValues The predefined values for the class.
     * @param object $classInstance The class instance.
     *
     * @return array|null An array containing the value to set, or null if not set.
     */
    private function setWithPredefined(
        ReflectionProperty $property,
        array $classPropertyValues,
        object $classInstance,
    ): ?array {
        $propName = $property->getName();
        if ($property->isStatic() && isset($classPropertyValues[$propName])) {
            return [$classPropertyValues[$propName]];
        }
        if (isset($classPropertyValues[$propName])) {
            return [$classInstance, $classPropertyValues[$propName]];
        }
        if (!$this->repository->isPropertyAttributeEnabled()) {
            return [];
        }

        return null;
    }

    /**
     * Resolve a property without an argument.
     *
     * If the property has a `#[Infuse]` attribute with no arguments, this method
     * is called to resolve the value. It will throw a
     * `ContainerException` if the property type is not a class or interface.
     * If the type is an interface, it will check for an environment-based
     * override before resolving the class.
     *
     * @param ReflectionProperty $property The property to resolve.
     * @param ReflectionType|null $parameterType The type of the property.
     *
     * @return object The resolved value.
     *
     * @throws ContainerException|ReflectionException
     */
    private function resolveWithoutArgument(
        ReflectionProperty $property,
        ?ReflectionType $parameterType,
    ): object {
        if (!$parameterType instanceof ReflectionNamedType || $parameterType->isBuiltin()) {
            throw new ContainerException(
                'Malformed #[Infuse] or invalid property type on ' .
                "{$property->getDeclaringClass()->getName()}::\${$property->getName()}",
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
