<?php

namespace AbmmHasan\InterMix\DI\Resolver;

use AbmmHasan\InterMix\DI\Attribute\Infuse;
use AbmmHasan\InterMix\Exceptions\ContainerException;
use ReflectionClass;
use ReflectionException;
use ReflectionProperty;

class PropertyResolver
{
    use ReflectionResource;

    private ClassResolver $classResolver;

    private object $classInstance;

    /**
     * @param Repository $repository
     */
    public function __construct(
        private Repository $repository
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
        $this->classInstance = $this->repository->resolvedResource[$class->getName()]['instance'];
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
            !$this->repository->enableAttribute
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

        return $this->resolveAttribute($property, $attribute);
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

            !$this->repository->enableAttribute => [],

            default => null
        };
    }

    /**
     * Resolve using attribute
     *
     * @param ReflectionProperty $property
     * @param array $attribute
     * @return array
     * @throws ContainerException|ReflectionException
     */
    private function resolveAttribute(ReflectionProperty $property, array $attribute): array
    {
        $arguments = $attribute[0]->getArguments();
        $parameterType = $property->getType();

        if ($arguments === []) {
            if ($parameterType === null || $parameterType->isBuiltin()) {
                throw new ContainerException(
                    sprintf(
                        "Malformed #[Infuse] attribute detected on %s::$%s",
                        $property->getDeclaringClass()->getName(),
                        $property->getName()
                    )
                );
            }
            return [
                $this->classInstance,
                $this->classResolver->resolve(
                    $this->reflectedClass($parameterType->getName())
                )['instance']
            ];
        }

        $infuse = $attribute[0]->newInstance();

        return match ($infuse->getData('type')) {
            'name' => [
                $this->classInstance,
                $this->repository->functionReference[$infuse->getData('data')] ??
                throw new ContainerException(
                    sprintf(
                        "Unknown definition (%s) detected on %s::$%s",
                        $infuse->getData('data'),
                        $property->getDeclaringClass()->getName(),
                        $property->getName()
                    )
                )
            ],
            default => throw new ContainerException(
                sprintf(
                    "Unknown #[Infuse] parameter detected on %s::$%s",
                    $property->getDeclaringClass()->getName(),
                    $property->getName()
                )
            )
        };
    }
}
