<?php

namespace AbmmHasan\InterMix\DI\Resolver;

use AbmmHasan\InterMix\DI\Attribute\Infuse;
use AbmmHasan\InterMix\Exceptions\ContainerException;
use Psr\Cache\InvalidArgumentException;
use ReflectionClass;
use ReflectionException;
use ReflectionNamedType;
use ReflectionProperty;
use ReflectionType;

class PropertyResolver
{
    use Reflector;

    private ClassResolver $classResolver;

    /**
     * Constructs a new instance of the class.
     *
     * @param Repository $repository The repository object.
     * @param ParameterResolver $parameterResolver The parameter resolver object.
     */
    public function __construct(
        private readonly Repository $repository,
        private readonly ParameterResolver $parameterResolver
    ) {
    }

    /**
     * Set the class resolver instance.
     *
     * @param ClassResolver $classResolver The class resolver instance.
     * @return void
     */
    public function setClassResolverInstance(ClassResolver $classResolver): void
    {
        $this->classResolver = $classResolver;
    }

    /**
     * Resolves the properties of a given class.
     *
     * @param ReflectionClass $class The ReflectionClass instance of the class.
     * @return void
     * @throws ContainerException|ReflectionException|InvalidArgumentException
     */
    public function resolve(ReflectionClass $class): void
    {
        $className = $class->getName();
        $this->resolveProperties(
            $class,
            $class->getProperties(),
            $this->repository->resolvedResource[$className]['instance']
        );
        if ($parentClass = $class->getParentClass()) {
            $this->resolveProperties(
                $parentClass,
                $parentClass->getProperties(ReflectionProperty::IS_PRIVATE),
                $this->repository->resolvedResource[$className]['instance']
            );
        }
        $this->repository->resolvedResource[$className]['property'] = true;
    }

    /**
     * Resolves the properties of a class instance.
     *
     * @param ReflectionClass $class The reflection class object.
     * @param array $properties The array of properties to be resolved.
     * @param object $classInstance The instance of the class.
     * @return void
     * @throws ContainerException|ReflectionException|InvalidArgumentException
     */
    private function resolveProperties(ReflectionClass $class, array $properties, object $classInstance): void
    {
        if ($properties === []) {
            return;
        }

        $className = $class->getName();
        if (
            !isset($this->repository->classResource[$className]['property']) &&
            !$this->repository->enablePropertyAttribute
        ) {
            return;
        }

        $classPropertyValues = $this->repository->classResource[$className]['property'] ?? [];

        /** @var  ReflectionProperty $property */
        foreach ($properties as $property) {
            if ($property->isPromoted()) {
                continue;
            }

            // required for PHP 8.0 only
            $property->setAccessible(true);

            $values = $this->resolveValue($property, $classPropertyValues, $classInstance);
            !$values ?: $property->setValue(...$values);
        }
    }

    /**
     * Resolves the value of a property.
     *
     * @param ReflectionProperty $property The reflection property.
     * @param mixed $classPropertyValues The property values of the class.
     * @param object $classInstance The instance of the class.
     * @return array The resolved property value.
     * @throws ContainerException|ReflectionException|InvalidArgumentException
     */
    private function resolveValue(
        ReflectionProperty $property,
        mixed $classPropertyValues,
        object $classInstance
    ): array {
        $propertyValue = $this->setWithPredefined($property, $classPropertyValues, $classInstance);

        if ($propertyValue !== null) {
            return $propertyValue;
        }

        $attribute = $property->getAttributes(Infuse::class);
        if (!$attribute) {
            return [];
        }

        $parameterType = $property->getType();

        return [
            $classInstance,
            match ($attribute[0]->getArguments() === []) {
                true => $this->resolveWithoutArgument($property, $parameterType),
                default => $this->classResolver->resolveInfuse($attribute[0]->newInstance())
                    ?? throw new ContainerException(
                        "Unknown #[Infuse] parameter detected on
                        {$property->getDeclaringClass()->getName()}::\${$property->getName()}"
                    )
            }
        ];
    }

    /**
     * Sets the value of a property with predefined values.
     *
     * @param ReflectionProperty $property The reflection property.
     * @param array $classPropertyValues The array of class property values.
     * @param object $classInstance The class instance.
     * @return array|null The resulting array or null.
     */
    private function setWithPredefined(
        ReflectionProperty $property,
        array $classPropertyValues,
        object $classInstance
    ): ?array {
        return match (true) {
            $property->isStatic() && isset($classPropertyValues[$property->getName()]) => [
                $classPropertyValues[$property->getName()]
            ],

            isset($classPropertyValues[$property->getName()]) => [
                $classInstance,
                $classPropertyValues[$property->getName()]
            ],

            !$this->repository->enablePropertyAttribute => [],

            default => null
        };
    }

    /**
     * Resolves the property without any argument.
     *
     * @param ReflectionProperty $property The reflection property.
     * @param ReflectionType|null $parameterType The reflection parameter type.
     * @return object The resolved object.
     * @throws ContainerException|ReflectionException|InvalidArgumentException
     */
    private function resolveWithoutArgument(ReflectionProperty $property, ReflectionType $parameterType = null): object
    {
        if (!$parameterType instanceof ReflectionNamedType || $parameterType->isBuiltin()) {
            throw new ContainerException(
                "Malformed #[Infuse] attribute or property type detected on
                {$property->getDeclaringClass()->getName()}::\${$property->getName()}"
            );
        }
        return $this->classResolver->resolve(
            $this->reflectedClass($parameterType->getName())
        )['instance'];
    }
}
