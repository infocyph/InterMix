<?php

declare(strict_types=1);

namespace Infocyph\InterMix\DI\Build;

use ReflectionClass;

/** @internal */
final class StaticFeatureClassifier
{
    /**
     * Explain class features requiring runtime resolution, or return null when native planning is
     * permitted.
     *
     * @param ReflectionClass<object> $class
     */
    public function dynamicReason(DefinitionGraph $graph, ReflectionClass $class): ?string
    {
        if (!$graph->injectionEnabled()) {
            return 'injection-off class requires generic runtime';
        }

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
