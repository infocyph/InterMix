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

        if (array_key_exists('constructor', $resources)) {
            return 'class has registered constructor parameters';
        }
        if (array_key_exists('method', $resources)) {
            return 'class has registered method invocation';
        }

        foreach (array_keys($resources) as $resource) {
            if ($resource !== 'property') {
                return 'class has registered runtime resources';
            }
        }

        return null;
    }
}
