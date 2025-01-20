<?php

declare(strict_types=1);

namespace Infocyph\InterMix\DI\Resolver;

use Infocyph\InterMix\DI\Attribute\Infuse;
use Infocyph\InterMix\DI\Reflection\ReflectionResource;
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
     * Constructor.
     */
    public function __construct(
        private Repository $repository,
        private readonly ParameterResolver $parameterResolver
    ) {
        //
    }

    /**
     * Inject a ClassResolver instance so we can call ->resolveInfuse().
     */
    public function setClassResolverInstance(ClassResolver $classResolver): void
    {
        $this->classResolver = $classResolver;
    }

    /**
     * Resolves the properties of the given class (and possibly its parent’s private properties).
     *
     * @throws ContainerException|ReflectionException|InvalidArgumentException
     */
    public function resolve(ReflectionClass $class): void
    {
        $className = $class->getName();

        // Retrieve existing resource info for this class
        $resolvedResource = $this->repository->getResolvedResource()[$className] ?? [];

        // We expect an instance in resolvedResource[$className]['instance']
        if (!isset($resolvedResource['instance'])) {
            // If there's no instance yet, there's nothing to set on
            return;
        }

        // Process current class properties
        $this->processProperties(
            $class,
            $class->getProperties(),
            $resolvedResource['instance']
        );

        // Process parent class private properties (if any)
        if ($parentClass = $class->getParentClass()) {
            $this->processProperties(
                $parentClass,
                $parentClass->getProperties(ReflectionProperty::IS_PRIVATE),
                $resolvedResource['instance']
            );
        }

        // Mark property injection as done
        $resolvedResource['property'] = true;
        $this->repository->setResolvedResource($className, $resolvedResource);
    }

    /**
     * Iterates over an array of ReflectionProperty objects,
     * resolving (injecting) each property if needed.
     */
    private function processProperties(
        ReflectionClass $class,
        array $properties,
        object $classInstance
    ): void {
        // If no properties, do nothing
        if (!$properties) {
            return;
        }

        $className = $class->getName();

        // Check if we even do property injection for this class
        // (either the user registered some property or property attributes are enabled)
        $classResource  = $this->repository->getClassResource();
        $registeredProps = $classResource[$className]['property'] ?? null;

        if ($registeredProps === null && !$this->repository->isPropertyAttributeEnabled()) {
            // No property array & attribute-based injection disabled => skip
            return;
        }

        // If property array is set, store it for quick usage
        $classPropertyValues = $registeredProps ?? [];

        // For each property, see if we should inject a value
        foreach ($properties as $property) {
            // Skip promoted properties (already handled in the constructor)
            if ($property->isPromoted()) {
                continue;
            }

            // Attempt to resolve the property’s value
            $values = $this->resolveValue($property, $classPropertyValues, $classInstance);

            // If $values is a non-empty array, $property->setValue(...$values)
            if ($values) {
                $property->setValue(...$values);
            }
        }
    }

    /**
     * Determines the correct value to inject into a single property.
     *
     * @return array e.g. [objectToSetOn, valueToSet]
     *               or empty array if no injection occurs
     */
    private function resolveValue(
        ReflectionProperty $property,
        array $classPropertyValues,
        object $classInstance
    ): array {
        // 1) Check if a predefined property value was set by the user
        $predefined = $this->setWithPredefined($property, $classPropertyValues, $classInstance);
        if ($predefined !== null) {
            // If $predefined is an empty array => no injection
            // If it's an array => we have an injection
            return $predefined;
        }

        // 2) If property-level attribute injection is disabled or no Infuse attribute => skip
        if (!$this->repository->isPropertyAttributeEnabled()) {
            return [];
        }
        $attribute = $property->getAttributes(Infuse::class);
        if (!$attribute) {
            return [];
        }

        // 3) There's an Infuse attribute
        $parameterType = $property->getType();

        // If attribute arguments are empty => we do 'resolveWithoutArgument'
        // else we call ->resolveInfuse()
        return [
            $classInstance,
            match (empty($attribute[0]->getArguments())) {
                true => $this->resolveWithoutArgument($property, $parameterType),
                default => $this->classResolver->resolveInfuse(
                    $attribute[0]->newInstance()
                ) ?? throw new ContainerException(
                    sprintf(
                        "Unknown #[Infuse] parameter detected on %s::\$%s",
                        $property->getDeclaringClass()->getName(),
                        $property->getName()
                    )
                ),
            },
        ];
    }

    /**
     * Attempts to use user-supplied property values (from $classPropertyValues)
     * for the property. If found, return an array for setValue(). If not found, return:
     *   - empty array [] if property injection is disabled,
     *   - null if we want to continue with attribute injection.
     *
     * @return array|null e.g.:
     *   - [ $classInstance, $value ] if found,
     *   - [ $value ] if property is static,
     *   - [] if injection is effectively skipped,
     *   - null if we want to proceed to attribute injection
     */
    private function setWithPredefined(
        ReflectionProperty $property,
        array $classPropertyValues,
        object $classInstance
    ): ?array {
        $propName = $property->getName();

        // If static + user has a value => we pass just that single arg
        if ($property->isStatic() && isset($classPropertyValues[$propName])) {
            return [$classPropertyValues[$propName]];
        }

        // If non-static + user has a value => pass [object, value]
        if (isset($classPropertyValues[$propName])) {
            return [$classInstance, $classPropertyValues[$propName]];
        }

        // If property attributes are disabled => skip entirely
        if (!$this->repository->isPropertyAttributeEnabled()) {
            // Return empty array => no injection
            return [];
        }

        // Return null => we continue to the attribute logic
        return null;
    }

    /**
     * Handles property injection when the attribute had no arguments,
     * so we rely on the property’s type to reflect which class to inject.
     *
     * @throws ContainerException|ReflectionException|InvalidArgumentException
     */
    private function resolveWithoutArgument(
        ReflectionProperty $property,
        ?ReflectionType $parameterType = null
    ): object {
        // The property must have a non-built-in type for us to resolve
        if (!$parameterType instanceof ReflectionNamedType || $parameterType->isBuiltin()) {
            throw new ContainerException(
                sprintf(
                    "Malformed #[Infuse] attribute or invalid property type on %s::\$%s",
                    $property->getDeclaringClass()->getName(),
                    $property->getName()
                )
            );
        }

        // Reflect the class type
        $classReflection = ReflectionResource::getClassReflection($parameterType->getName());

        // Resolve + return the instance
        return $this->classResolver->resolve($classReflection)['instance'];
    }
}
