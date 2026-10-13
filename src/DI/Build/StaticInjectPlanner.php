<?php

declare(strict_types=1);

namespace Infocyph\InterMix\DI\Build;

use Infocyph\InterMix\DI\Attribute\Inject;
use Infocyph\InterMix\DI\Internal\BoundedValueInspector;
use ReflectionMethod;
use ReflectionNamedType;
use ReflectionParameter;

/**
 * @phpstan-type ServiceArgument array{kind: 'service', id: string}|array{kind: 'value', code: string}
 * @internal
 */
final class StaticInjectPlanner
{
    /**
     * @return array<string, ServiceArgument>|string
     */
    public function methodArguments(DefinitionGraph $graph, ReflectionMethod $method): array|string
    {
        $attribute = $method->getAttributes(Inject::class)[0] ?? null;
        if ($attribute === null || $attribute->getArguments() === []) {
            return [];
        }

        $values = $attribute->newInstance()->getMethodArguments();
        if (!is_array($values)) {
            return [];
        }

        $planned = [];
        foreach ($method->getParameters() as $parameter) {
            $name = $parameter->getName();
            if (!array_key_exists($name, $values)) {
                continue;
            }
            $argument = $this->methodParameterPlan($graph, $parameter, $values[$name]);
            if (is_string($argument)) {
                return $argument;
            }
            if ($argument !== null) {
                $planned[$name] = $argument;
            }
        }

        return $planned;
    }

    /** @return array{kind: 'service', id: string}|string|null */
    public function parameterArgument(DefinitionGraph $graph, ReflectionParameter $parameter): array|string|null
    {
        $attribute = $parameter->getAttributes(Inject::class)[0] ?? null;
        if ($attribute === null || $attribute->getArguments() === []) {
            return null;
        }

        $inject = $attribute->newInstance();
        $data = $inject->getParameterData();
        if (!is_array($data)) {
            return null;
        }

        $target = $data['type'] ?? null;
        if (!is_string($target) || $target === '') {
            return null;
        }

        return $this->serviceTargetPlan($graph, $target, $parameter->getName())
            ?? "parameter '{$parameter->getName()}' has an unresolved #[Inject] target '{$target}'";
    }

    /** @return ServiceArgument|string|null */
    private function methodParameterPlan(DefinitionGraph $graph, ReflectionParameter $parameter, mixed $value): array|string|null
    {
        $name = $parameter->getName();
        $type = $parameter->getType();
        if ($type !== null && (!$type instanceof ReflectionNamedType || !$type->isBuiltin())) {
            return "method parameter '{$name}' has typed method-level #[Inject] semantics";
        }

        return $type === null && $graph->hasDefinition($name)
            ? null
            : $this->methodValuePlan($graph, $value, $name);
    }

    /** @return ServiceArgument|string */
    private function methodValuePlan(DefinitionGraph $graph, mixed $value, string $parameter): array|string
    {
        if (!is_string($value)) {
            if (!BoundedValueInspector::isScalarNullArray($value)) {
                return "method parameter '{$parameter}' has a non-exportable #[Inject] value";
            }

            return ['kind' => 'value', 'code' => var_export($value, true)];
        }

        if ($graph->hasDefinition($value)) {
            return ['kind' => 'service', 'id' => $value];
        }
        if (function_exists($value)) {
            return "method parameter '{$parameter}' injects a runtime function";
        }

        return ['kind' => 'value', 'code' => var_export($value, true)];
    }

    /** @return array{kind: 'service', id: string}|string|null */
    private function serviceTargetPlan(DefinitionGraph $graph, string $target, string $parameter): array|string|null
    {
        if ($graph->hasDefinition($target)) {
            return ['kind' => 'service', 'id' => $target];
        }
        if (function_exists($target)) {
            return "parameter '{$parameter}' injects a runtime function";
        }
        if (!class_exists($target) && !interface_exists($target)) {
            return null;
        }

        $concrete = $graph->environmentConcrete($target) ?? $target;
        if (!class_exists($concrete)) {
            return "parameter '{$parameter}' injects an unresolved interface '{$target}'";
        }

        return ['kind' => 'service', 'id' => $concrete];
    }
}
