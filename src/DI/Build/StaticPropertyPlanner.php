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
            $declaring = $current->getName();
            $registered = $this->registeredProperties($graph, $declaring);

            foreach ($current->getProperties() as $property) {
                if ($property->getDeclaringClass()->getName() !== $declaring) {
                    continue;
                }

                $argument = null;
                $name = $property->getName();
                if (array_key_exists($name, $registered)) {
                    if (!$property->isPublic() || $property->isReadOnly()) {
                        return "property '{$declaring}::\${$name}' requires reflection-based injection";
                    }
                    if (!$this->isExportable($registered[$name])) {
                        return "property '{$declaring}::\${$name}' has a non-exportable registered value";
                    }
                    $argument = [
                        'kind' => 'value',
                        'code' => var_export($registered[$name], true),
                    ];
                } elseif ($graph->propertyAttributesEnabled()) {
                    $argument = $this->attributeArgument($graph, $property);
                    if (is_string($argument)) {
                        return $argument;
                    }
                    if ($argument !== null && (!$property->isPublic() || $property->isReadOnly())) {
                        return "property '{$declaring}::\${$name}' requires reflection-based attribute injection";
                    }
                }

                if (!is_array($argument)) {
                    continue;
                }

                if ($argument['kind'] === 'service' && !isset($seenDependencies[$argument['id']])) {
                    $seenDependencies[$argument['id']] = true;
                    $dependencies[] = $argument['id'];
                }

                $properties[] = [
                    'declaring' => $declaring,
                    'property' => $name,
                    'static' => $property->isStatic(),
                    'argument' => $argument,
                ];
            }
        }

        return ['properties' => $properties, 'dependencies' => $dependencies];
    }

    /** @return ServiceArgument|string|null */
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

    /** @return ServiceArgument|string */
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
