<?php

namespace AbmmHasan\InterMix\DI\Resolver;

use AbmmHasan\InterMix\DI\Attribute\Infuse;
use AbmmHasan\InterMix\Exceptions\ContainerException;
use ReflectionClass;
use ReflectionException;
use ReflectionNamedType;
use ReflectionProperty;
use ReflectionType;

class PropertyResolver
{
    use Reflector;

    private ClassResolver $classResolver;

    private object $classInstance;

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
     * Set class resolver instance
     *
     * @param ClassResolver $classResolver
     * @return void
     */
    public function setClassResolverInstance(ClassResolver $classResolver): void
    {
        $this->classResolver = $classResolver;
    }

    /**
     * Resolve class properties
     *
     * @param ReflectionClass $class
     * @return void
     * @throws ContainerException|ReflectionException
     */
    public function resolve(ReflectionClass $class): void
    {
        $className = $class->getName();
        $this->classInstance = $this->repository->resolvedResource[$className]['instance'];
        $this->resolveProperties(
            $class,
            $class->getProperties()
        );
        if ($parentClass = $class->getParentClass()) {
            $this->resolveProperties(
                $parentClass,
                $parentClass->getProperties(ReflectionProperty::IS_PRIVATE)
            );
        }
        $this->repository->resolvedResource[$className]['property'] = true;
    }

    /**
     * Resolve properties
     *
     * @param ReflectionClass $class
     * @param array $properties
     * @return void
     * @throws ContainerException|ReflectionException
     */
    private function resolveProperties(ReflectionClass $class, array $properties): void
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

        foreach ($properties as $property) {
            if ($property->isPromoted()) {
                continue;
            }

            $values = $this->resolveValue($property, $classPropertyValues);

            if ($values) {
                $property->setValue(...$values);
            }
        }
    }

    /**
     * Resolve property value
     *
     * @param ReflectionProperty $property
     * @param $classPropertyValues
     * @return array
     * @throws ContainerException|ReflectionException
     */
    private function resolveValue(ReflectionProperty $property, $classPropertyValues): array
    {
        $propertyValue = $this->setWithPredefined($property, $classPropertyValues);

        if ($propertyValue !== null) {
            return $propertyValue;
        }

        $attribute = $property->getAttributes(Infuse::class);
        if (!$attribute) {
            return [];
        }

        $parameterType = $property->getType();

        return [
            $this->classInstance,
            match ($attribute[0]->getArguments() === []) {
                true => $this->resolveWithoutArgument($property, $parameterType),
                default => $this->classResolver->resolveInfuse($attribute[0]->newInstance())
                    ?? throw new ContainerException(
                        sprintf(
                            "Unknown #[Ink] parameter detected on %s::$%s",
                            $property->getDeclaringClass()->getName(),
                            $property->getName()
                        )
                    )
            }
        ];
    }

    /**
     * Check & get predefined value if available
     *
     * @param ReflectionProperty $property
     * @param array $classPropertyValues
     * @return array|null
     */
    private function setWithPredefined(ReflectionProperty $property, array $classPropertyValues): ?array
    {
        return match (true) {
            $property->isStatic() && isset($classPropertyValues[$property->getName()]) => [
                $classPropertyValues[$property->getName()]
            ],

            isset($classPropertyValues[$property->getName()]) => [
                $this->classInstance,
                $classPropertyValues[$property->getName()]
            ],

            !$this->repository->enablePropertyAttribute => [],

            default => null
        };
    }

    /**
     * Resolve attribute without arguments
     *
     * @param ReflectionProperty $property
     * @param ReflectionType|null $parameterType
     * @return object
     * @throws ContainerException
     * @throws ReflectionException
     */
    private function resolveWithoutArgument(ReflectionProperty $property, ReflectionType $parameterType = null): object
    {
        if (!$parameterType instanceof ReflectionNamedType || $parameterType->isBuiltin()) {
            throw new ContainerException(
                sprintf(
                    "Malformed #[Ink] attribute or property type, on %s::$%s",
                    $property->getDeclaringClass()->getName(),
                    $property->getName()
                )
            );
        }
        return $this->classResolver->resolve(
            $this->reflectedClass($parameterType->getName())
        )['instance'];
    }
}
