<?php

declare(strict_types=1);

namespace Infocyph\InterMix\DI\Build;

use Infocyph\InterMix\DI\Support\LifetimeEnum;
use Infocyph\InterMix\Internal\ReflectionResource;

/**
 * @phpstan-type ServiceArgument array{kind: 'service', id: string}|array{kind: 'value', code: string}
 * @phpstan-type PropertyPlan array{declaring: class-string, property: string, static: bool, argument: ServiceArgument}
 * @phpstan-type MethodPlan array{method: string, arguments: list<ServiceArgument>, dependencies: list<string>}
 * @phpstan-type InvocationPlan array{kind: 'invocation', class: class-string, lifetime: LifetimeEnum, arguments: list<ServiceArgument>, properties: list<PropertyPlan>, invocation: MethodPlan, dependencies: list<string>}
 * @internal
 */
final class StaticInvocationPlanner
{
    /** @return InvocationPlan|string */
    public function plan(DefinitionGraph $graph, string $id, string $className, string $methodName): array|string
    {
        if (!class_exists($className)) {
            return 'invocation class does not exist';
        }

        $class = ReflectionResource::getClassReflection($className);
        if (!$class->isInstantiable()) {
            return 'invocation class is not instantiable';
        }

        $dynamicReason = new StaticFeatureClassifier()->dynamicReason($graph, $class);
        if ($dynamicReason !== null) {
            return $dynamicReason;
        }

        $constructor = new StaticParameterPlanner()->constructorPlan($graph, $class);
        if (is_string($constructor)) {
            return $constructor;
        }
        $property = new StaticPropertyPlanner()->plan($graph, $class);
        if (is_string($property)) {
            return $property;
        }
        $invocation = new StaticMethodPlanner()->explicitPlan($graph, $class, $methodName);
        if (is_string($invocation)) {
            return $invocation;
        }

        return [
            'kind' => 'invocation',
            'class' => $class->getName(),
            'lifetime' => $graph->definitionMetaFor($id)['lifetime'],
            'arguments' => $constructor['arguments'],
            'properties' => $property['properties'],
            'invocation' => $invocation,
            'dependencies' => array_values(array_unique([
                ...$constructor['dependencies'],
                ...$property['dependencies'],
                ...$invocation['dependencies'],
            ])),
        ];
    }
}
