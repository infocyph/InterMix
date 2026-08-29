<?php

declare(strict_types=1);

namespace Infocyph\InterMix\DI\Resolver;

use Infocyph\InterMix\DI\Attribute\AttributeResolution;
use Infocyph\InterMix\DI\Attribute\Inject;
use Infocyph\InterMix\DI\Support\TraceLevelEnum;
use Infocyph\InterMix\Exceptions\ContainerException;
use Psr\Cache\InvalidArgumentException;
use ReflectionClass;
use ReflectionException;
use ReflectionNamedType;
use ReflectionProperty;

class PropertyResolver
{
    private const int PROPERTY_PLAN_CACHE_LIMIT = 1024;

    private ClassResolver $classResolver;

    /** @var array<string, array<int, ReflectionProperty>> */
    private array $propertyPlanCache = [];

    public function __construct(
        private readonly Repository $repository,
    ) {}

    /**
     * @param ReflectionClass<object> $class
     * @throws ContainerException|ReflectionException|InvalidArgumentException
     */
    public function resolve(ReflectionClass $class, object $instance): void
    {
        if (!$this->hasPropertyWork($class)) {
            return;
        }

        $propertyAttributes = $this->repository->isPropertyAttributeEnabled();
        $registeredByClass = [];

        foreach ($this->getPropertyPlan($class) as $property) {
            $declaring = $property->getDeclaringClass()->getName();
            $registered = $registeredByClass[$declaring]
                ??= $this->getRegisteredProperties($declaring);
            $name = $property->getName();

            if (array_key_exists($name, $registered)) {
                $this->setPropertyValue($property, $instance, $registered[$name]);

                continue;
            }

            if (!$propertyAttributes
                || ($property->isPromoted() && $property->getAttributes() === [])
            ) {
                continue;
            }

            $this->tracePropertyResolution($property);
            $value = $this->resolveAttributedValue($property);
            if ($value !== AttributeResolution::Unresolved) {
                $this->setPropertyValue($property, $instance, $value);
            }
        }
    }

    public function setClassResolverInstance(ClassResolver $classResolver): void
    {
        $this->classResolver = $classResolver;
    }

    /** @param ReflectionClass<object> $class */
    private function hasPropertyWork(ReflectionClass $class): bool
    {
        if ($this->repository->isPropertyAttributeEnabled()) {
            return true;
        }

        for ($current = $class; $current instanceof ReflectionClass; $current = $current->getParentClass()) {
            $properties = $this->repository->getClassResourceFor($current->getName())['property'] ?? null;
            if (is_array($properties) && $properties !== []) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param ReflectionClass<object> $class
     * @return array<int, ReflectionProperty>
     */
    private function getPropertyPlan(ReflectionClass $class): array
    {
        $className = $class->getName();
        if (isset($this->propertyPlanCache[$className])) {
            return $this->propertyPlanCache[$className];
        }

        $properties = [];
        for ($current = $class; $current instanceof ReflectionClass; $current = $current->getParentClass()) {
            $declaringClass = $current->getName();
            foreach ($current->getProperties() as $property) {
                if ($property->getDeclaringClass()->getName() === $declaringClass) {
                    $properties[] = $property;
                }
            }
        }

        if (count($this->propertyPlanCache) >= self::PROPERTY_PLAN_CACHE_LIMIT) {
            unset($this->propertyPlanCache[array_key_first($this->propertyPlanCache)]);
        }

        return $this->propertyPlanCache[$className] = $properties;
    }

    /** @return array<string, mixed> */
    private function getRegisteredProperties(string $className): array
    {
        $properties = $this->repository->getClassResourceFor($className)['property'] ?? [];
        if (!is_array($properties)) {
            return [];
        }

        $registered = [];
        foreach ($properties as $name => $value) {
            if (is_string($name)) {
                $registered[$name] = $value;
            }
        }

        return $registered;
    }

    /** @throws ContainerException|ReflectionException|InvalidArgumentException */
    private function resolveAttributedValue(ReflectionProperty $property): mixed
    {
        $inject = $property->getAttributes(Inject::class)[0] ?? null;
        if ($inject !== null) {
            if ($inject->getArguments() === []) {
                return $this->resolveWithoutArgument($property);
            }

            return $this->classResolver->resolveInject($inject->newInstance());
        }

        foreach ($property->getAttributes() as $attribute) {
            $instance = $attribute->newInstance();
            $registry = $this->repository->attributeRegistry();
            if (!$registry->has($instance::class)) {
                continue;
            }

            $value = $registry->resolve($instance, $property);
            if ($value !== AttributeResolution::Unresolved) {
                return $value;
            }
        }

        return AttributeResolution::Unresolved;
    }

    /** @throws ContainerException|ReflectionException|InvalidArgumentException */
    private function resolveWithoutArgument(ReflectionProperty $property): mixed
    {
        $type = $property->getType();
        if (!$type instanceof ReflectionNamedType || $type->isBuiltin()) {
            throw new ContainerException(
                'Malformed #[Inject] or invalid property type on '
                . "{$property->getDeclaringClass()->getName()}::\${$property->getName()}",
            );
        }

        $resolved = $this->classResolver->resolveInject(new Inject($type->getName()));
        if ($resolved === AttributeResolution::Unresolved) {
            throw new ContainerException(
                "Failed to resolve {$type->getName()} for property injection.",
            );
        }

        return $resolved;
    }

    private function setPropertyValue(ReflectionProperty $property, object $instance, mixed $value): void
    {
        $property->setValue($property->isStatic() ? null : $instance, $value);
    }

    private function tracePropertyResolution(ReflectionProperty $property): void
    {
        if ($this->repository->isTracingEnabled()) {
            $this->repository->tracer()->push(
                "prop {$property->getName()} of {$property->getDeclaringClass()->getName()}",
                TraceLevelEnum::Verbose,
            );
        }
    }
}
