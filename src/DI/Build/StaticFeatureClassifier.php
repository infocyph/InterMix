<?php

declare(strict_types=1);

namespace Infocyph\InterMix\DI\Build;

use Infocyph\InterMix\DI\Attribute\Inject;
use ReflectionClass;
use ReflectionProperty;

/** @internal */
final class StaticFeatureClassifier
{
    /** @param ReflectionClass<object> $class */
    public function dynamicReason(DefinitionGraph $graph, ReflectionClass $class): ?string
    {
        $resources = $graph->classResourcesFor($class->getName());
        if ($resources !== []) {
            return $this->classResourceReason($resources);
        }

        if ($graph->propertyAttributesEnabled() && $this->hasDynamicPropertyAttributes($graph, $class)) {
            return 'class has runtime property attributes';
        }

        return null;
    }

    /** @param array<string, mixed> $resources */
    private function classResourceReason(array $resources): string
    {
        if (array_key_exists('constructor', $resources)) {
            return 'class has registered constructor parameters';
        }
        if (array_key_exists('property', $resources)) {
            return 'class has registered property injection';
        }
        if (array_key_exists('method', $resources)) {
            return 'class has registered method invocation';
        }

        return 'class has registered runtime resources';
    }

    /** @param ReflectionClass<object> $class */
    private function hasDynamicPropertyAttributes(DefinitionGraph $graph, ReflectionClass $class): bool
    {
        $registered = array_fill_keys($graph->registeredAttributeTypes(), true);

        for ($current = $class; $current instanceof ReflectionClass; $current = $current->getParentClass()) {
            $declaring = $current->getName();
            foreach ($current->getProperties() as $property) {
                if ($property->getDeclaringClass()->getName() !== $declaring) {
                    continue;
                }
                if ($this->propertyRequiresRuntime($registered, $property)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * @param array<string, true> $registered
     */
    private function propertyRequiresRuntime(array $registered, ReflectionProperty $property): bool
    {
        foreach ($property->getAttributes() as $attribute) {
            $type = $attribute->getName();
            if ($type === Inject::class || isset($registered[$type])) {
                return true;
            }
        }

        return false;
    }
}
