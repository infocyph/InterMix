<?php

namespace AbmmHasan\InterMix\DI\Resolver;

use AbmmHasan\InterMix\DI\Attribute\Infuse;
use AbmmHasan\InterMix\Exceptions\ContainerException;
use ReflectionClass;
use ReflectionException;
use ReflectionFunction;
use ReflectionProperty;
use ReflectionType;

class PropertyResolver
{
    use ReflectionResource;

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

        $arguments = $attribute[0]->getArguments();
        $parameterType = $property->getType();

        return match ($arguments === []) {
            true => $this->resolveWithoutArgument($property, $parameterType),
            default => $this->resolveArguments($attribute[0]->newInstance(), $attribute)
        };
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
     * Resolve attribute without arguments
     *
     * @param ReflectionProperty $property
     * @param ReflectionType|null $parameterType
     * @return array
     * @throws ContainerException
     * @throws ReflectionException
     */
    private function resolveWithoutArgument(ReflectionProperty $property, ReflectionType $parameterType = null): array
    {
        if ($parameterType !== null && !$parameterType->isBuiltin()) {
            return [
                $this->classInstance,
                $this->classResolver->resolve(
                    $this->reflectedClass($parameterType->getName())
                )['instance']
            ];
        }
        throw new ContainerException(
            sprintf(
                "Malformed #[Infuse] attribute detected on %s::$%s",
                $property->getDeclaringClass()->getName(),
                $property->getName()
            )
        );
    }

    /**
     * Resolve attribute arguments
     *
     * @param Infuse $infuse
     * @param $property
     * @return array
     * @throws ContainerException|ReflectionException
     */
    private function resolveArguments(Infuse $infuse, $property): array
    {
        $type = $infuse->getData('type');

        if ($type !== 'name') {
            if (function_exists($type)) {
                return [
                    $this->classInstance,
                    $type(
                        ...
                        $this->parameterResolver->resolve(
                            new ReflectionFunction($type),
                            $infuse->getData('data'),
                            'constructor'
                        )
                    )
                ];
            }

            throw new ContainerException(
                sprintf(
                    "Unknown #[Infuse] parameter($type) detected on %s::$%s",
                    $property->getDeclaringClass()->getName(),
                    $property->getName()
                )
            );
        }

        // resolving 'name' parameter
        if (!isset($this->repository->functionReference[$infuse->getData('data')])) {
            throw new ContainerException(
                sprintf(
                    "Unknown definition (%s) detected on %s::$%s",
                    $infuse->getData('data'),
                    $property->getDeclaringClass()->getName(),
                    $property->getName()
                )
            );
        }

        return [
            $this->classInstance,
            $this->parameterResolver->resolveByDefinition(
                $this->repository->functionReference[$infuse->getData('data')],
                $infuse->getData('data')
            )
        ];
    }
}
