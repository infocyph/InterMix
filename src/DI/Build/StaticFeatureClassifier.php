<?php

declare(strict_types=1);

namespace Infocyph\InterMix\DI\Build;

use ReflectionClass;

/** @internal */
final class StaticFeatureClassifier
{
    /** @param ReflectionClass<object> $class */
    public function dynamicReason(DefinitionGraph $graph, ReflectionClass $class): ?string
    {
        $resources = $graph->classResourcesFor($class->getName());
        if ($resources === []) {
            return null;
        }

        foreach (array_keys($resources) as $resource) {
            if (!in_array($resource, ['constructor', 'method', 'property'], true)) {
                return 'class has registered runtime resources';
            }
        }

        return null;
    }
}
