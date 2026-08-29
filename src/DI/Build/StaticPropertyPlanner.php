<?php

declare(strict_types=1);

namespace Infocyph\InterMix\DI\Build;

use Infocyph\InterMix\DI\Attribute\Inject;
use ReflectionClass;
use ReflectionNamedType;
use ReflectionProperty;

/**
 * @phpstan-type ServiceArgument array{kind: 'service', id: string}|array{kind: 'value', code: string}
 * @phpstan-type PropertyPlan array{declaring: class-string, property: string, static: bool, argument: ServiceArgument}
 * @internal
 */
final class StaticPropertyPlanner
{
    /**
     * @param ReflectionClass<object> $class
     * @return array{properties: list<PropertyPlan>, dependencies: list<string>}|string
     */
    public function plan(DefinitionGraph $graph, ReflectionClass $class): array|string
    {
        $properties = [];
        $dependencies = [];
        $seenDependencies = [];

        for ($current = $class; $current instanceof ReflectionClass; $current = $current->getParentClass()) {
            $registered = $this->registeredProperties($graph, $current->getName());

            foreach ($current->getProperties() as $property) {
                if ($property->getDeclaringClass()->getName() !== $current->getName()) {
                    continue;
                }

                $plan = $this->propertyPlan($graph, $property, $registered);
                if (is_string($plan)) {
                    return $plan;
                }
                if ($plan === null) {
                    continue;
                }

                $properties[] = $plan;
                $this->appendDependency($plan, $dependencies, $seenDependencies);
            }
        }

        return ['properties' => $properties, 'dependencies' => $dependencies];
    }

    /**
     * @param PropertyPlan $plan
     * @param list<string> $dependencies
     * @param array<string, true> $seenDependencies
     */
    private function appendDependency(array $plan, array &$dependencies, array &$seenDependencies): void
    {
        $argument = $plan['argument'];
        if ($argument['kind'] !== 'service' || isset($seenDependencies[$argument['id']])) {
            return;
        }

        $seenDependencies[$argument['id']] = true;
        $dependencies[] = $argument['id'];
    }

    /** @return array{kind: 'service', id: string}|string|null */
    private function attributeArgument(DefinitionGraph $graph, ReflectionProperty $property): array|string|null
    {
        foreach ($property->getAttributes() as $attribute) {
            $type = $attribute->getName();
            if ($type !== Inject::class && $graph->hasAttributeType($type)) {
                return "property '{$property->getDeclaringClass()->getName()}::\${$property->getName()}' has a custom runtime attribute";
            }
        }

        $inject = $property->getAttributes(Inject::class)[0] ?? null;
        if ($inject === null) {
            return null;
        }
        if ($graph->hasAttributeType(Inject::class)) {
            return "property '{$property->getDeclaringClass()->getName()}::\${$property->getName()}' uses a custom Inject resolver";
        }

        if ($inject->getArguments() === []) {
            $type = $property->getType();
            if (!$type instanceof ReflectionNamedType || $type->isBuiltin()) {
                return "property '{$property->getDeclaringClass()->getName()}::\${$property->getName()}' has an unrepresentable #[Inject] target";
            }

            return $this->serviceArgument($graph, $this->normalizeRelativeType($property, $type->getName()));
        }

        $target = $inject->newInstance()->getParameterData('type');
        if (!is_string($target) || $target === '' || function_exists($target)) {
            return "property '{$property->getDeclaringClass()->getName()}::\${$property->getName()}' has a dynamic #[Inject] target";
        }

        return $this->serviceArgument($graph, $target);
    }

    private function isExportable(mixed $value): bool
    {
        if ($value === null || is_scalar($value)) {
            return true;
        }
        if (!is_array($value)) {
            return false;
        }

        return array_all($value, fn(mixed $item): bool => $this->isExportable($item));
    }

    private function normalizeRelativeType(ReflectionProperty $property, string $type): string
    {
        if ($type === 'self') {
            return $property->getDeclaringClass()->getName();
        }
        if ($type === 'parent') {
            $parent = $property->getDeclaringClass()->getParentClass();

            return $parent instanceof ReflectionClass ? $parent->getName() : $type;
        }

        return $type;
    }

    /**
     * @param array<string, mixed> $registered
     * @return PropertyPlan|string|null
     */
    private function propertyPlan(
        DefinitionGraph $graph,
        ReflectionProperty $property,
        array $registered,
    ): array|string|null {
        $name = $property->getName();
        $argument = null;

        if (array_key_exists($name, $registered)) {
            if (!$property->isPublic() || $property->isReadOnly()) {
                return "property '{$property->getDeclaringClass()->getName()}::\${$name}' requires reflection-based injection";
            }
            if (!$this->isExportable($registered[$name])) {
                return "property '{$property->getDeclaringClass()->getName()}::\${$name}' has a non-exportable registered value";
            }

            $argument = ['kind' => 'value', 'code' => var_export($registered[$name], true)];
        } elseif ($graph->propertyAttributesEnabled()) {
            $argument = $this->attributeArgument($graph, $property);
            if (is_string($argument)) {
                return $argument;
            }
            if ($argument !== null && (!$property->isPublic() || $property->isReadOnly())) {
                return "property '{$property->getDeclaringClass()->getName()}::\${$name}' requires reflection-based attribute injection";
            }
        }

        if ($argument === null) {
            return null;
        }

        return [
            'declaring' => $property->getDeclaringClass()->getName(),
            'property' => $name,
            'static' => $property->isStatic(),
            'argument' => $argument,
        ];
    }

    /** @return array<string, mixed> */
    private function registeredProperties(DefinitionGraph $graph, string $class): array
    {
        $raw = $graph->classResourcesFor($class)['property'] ?? [];
        if (!is_array($raw)) {
            return [];
        }

        $properties = [];
        foreach ($raw as $name => $value) {
            if (is_string($name)) {
                $properties[$name] = $value;
            }
        }

        return $properties;
    }

    /** @return array{kind: 'service', id: string}|string */
    private function serviceArgument(DefinitionGraph $graph, string $target): array|string
    {
        if ($graph->hasDefinition($target)) {
            return ['kind' => 'service', 'id' => $target];
        }

        $environment = $graph->environmentConcrete($target);
        if ($environment !== null) {
            return ['kind' => 'service', 'id' => $environment];
        }
        if (class_exists($target)) {
            return ['kind' => 'service', 'id' => $target];
        }

        return "property injection target '$target' is not statically resolvable";
    }
}
