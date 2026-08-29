<?php

declare(strict_types=1);

namespace Infocyph\InterMix\DI\Build;

use Infocyph\InterMix\DI\Support\FactoryDefinition;
use Infocyph\InterMix\DI\Support\ServiceReference;

/**
 * @phpstan-type ServiceArgument array{kind: 'service', id: string}|array{kind: 'value', code: string}
 * @phpstan-type FactoryPlan array{kind: 'factory', class: class-string, method: string|null, lifetime: \Infocyph\InterMix\DI\Support\LifetimeEnum, arguments: list<ServiceArgument>, properties: list<array{declaring: class-string, property: string, static: bool, argument: ServiceArgument}>, dependencies: list<string>}
 * @internal
 */
final class StaticFactoryPlanner
{
    /** @return FactoryPlan */
    public function plan(DefinitionGraph $graph, string $id, FactoryDefinition $definition): array
    {
        $arguments = [];
        $dependencies = [];
        $seenDependencies = [];

        foreach ($definition->arguments as $argument) {
            if (!$argument instanceof ServiceReference) {
                $arguments[] = ['kind' => 'value', 'code' => var_export($argument, true)];

                continue;
            }

            $arguments[] = ['kind' => 'service', 'id' => $argument->id];
            if (!isset($seenDependencies[$argument->id])) {
                $seenDependencies[$argument->id] = true;
                $dependencies[] = $argument->id;
            }
        }

        return [
            'kind' => 'factory',
            'class' => $definition->class,
            'method' => $definition->method,
            'lifetime' => $graph->definitionMetaFor($id)['lifetime'],
            'arguments' => $arguments,
            'properties' => [],
            'dependencies' => $dependencies,
        ];
    }
}
