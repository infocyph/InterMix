<?php

declare(strict_types=1);

namespace Infocyph\InterMix\DI\Build;

use Infocyph\InterMix\DI\Attribute\Inject;
use ReflectionClass;
use ReflectionNamedType;
use ReflectionProperty;

/**
 * @phpstan-type ServiceArgument array{kind: 'service', id: string}|array{kind: 'value', code: string}
 * @phpstan-type PropertyPlan array{declaring: class-string<object>, property: string, static: bool, argument: ServiceArgument|null, runtime?: 'attribute'|'assign'}
 * @internal
 */
final class StaticPropertyPlanner
{
    /**
     * @param ReflectionClass<object> $class
     * @return array{properties: list<PropertyPlan>, dependencies: list<string>}
     */
    public function plan(DefinitionGraph $graph, ReflectionClass $class): array
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
        if (!is_array($argument)
            || $argument['kind'] !== 'service'
            || isset($seenDependencies[$argument['id']])
        ) {
            return;
        }

        $seenDependencies[$argument['id']] = true;
        $dependencies[] = $argument['id'];
    }

    private function hasRuntimeAttribute(DefinitionGraph $graph, ReflectionProperty $property): bool
    {
        return array_any(
            $property->getAttributes(),
            static fn($attribute): bool => $attribute->getName() !== Inject::class
                && $graph->hasAttributeType($attribute->getName()),
        );
    }

    /** @return array{kind: 'service', id: string}|string|null */
    private function injectArgument(DefinitionGraph $graph, ReflectionProperty $property): array|string|null
    {
        $inject = $property->getAttributes(Inject::class)[0] ?? null;
        if ($inject === null) {
            return null;
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
     * @return PropertyPlan|null
     */
    private function propertyPlan(
        DefinitionGraph $graph,
        ReflectionProperty $property,
        array $registered,
    ): ?array {
        $name = $property->getName();
        if (array_key_exists($name, $registered)) {
            if (!$this->isExportable($registered[$name])) {
                return $this->runtimePropertyPlan($property);
            }

            return [
                'declaring' => $property->getDeclaringClass()->getName(),
                'property' => $name,
                'static' => $property->isStatic(),
                'argument' => ['kind' => 'value', 'code' => var_export($registered[$name], true)],
                ...(!$property->isPublic() || $property->isReadOnly() ? ['runtime' => 'assign'] : []),
            ];
        }

        if (!$graph->propertyAttributesEnabled()) {
            return null;
        }

        if ($property->getAttributes(Inject::class) !== []) {
            $argument = $this->injectArgument($graph, $property);
            if (is_string($argument)) {
                return $this->runtimePropertyPlan($property);
            }
            if ($argument === null) {
                return null;
            }

            return [
                'declaring' => $property->getDeclaringClass()->getName(),
                'property' => $name,
                'static' => $property->isStatic(),
                'argument' => $argument,
                ...(!$property->isPublic() || $property->isReadOnly() ? ['runtime' => 'assign'] : []),
            ];
        }

        return $this->hasRuntimeAttribute($graph, $property)
            ? $this->runtimePropertyPlan($property)
            : null;
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

    /** @return PropertyPlan */
    private function runtimePropertyPlan(ReflectionProperty $property): array
    {
        return [
            'declaring' => $property->getDeclaringClass()->getName(),
            'property' => $property->getName(),
            'static' => $property->isStatic(),
            'argument' => null,
            'runtime' => 'attribute',
        ];
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
