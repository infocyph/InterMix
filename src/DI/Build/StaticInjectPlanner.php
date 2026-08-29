<?php

declare(strict_types=1);

namespace Infocyph\InterMix\DI\Build;

use Infocyph\InterMix\DI\Attribute\Inject;
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

        $parameters = [];
        foreach ($method->getParameters() as $parameter) {
            $parameters[$parameter->getName()] = $parameter;
        }

        $planned = [];
        foreach ($values as $name => $value) {
            if (!is_string($name) || !isset($parameters[$name])) {
                continue;
            }

            $parameter = $parameters[$name];
            $type = $parameter->getType();
            if ($type !== null && (!$type instanceof ReflectionNamedType || !$type->isBuiltin())) {
                return "method parameter '{$name}' has typed method-level #[Inject] semantics";
            }
            if ($type === null && $graph->hasDefinition($name)) {
                continue;
            }

            $argument = $this->methodValuePlan($graph, $value, $name);
            if (is_string($argument)) {
                return $argument;
            }
            $planned[$name] = $argument;
        }

        return $planned;
    }

    /** @return ServiceArgument|string|null */
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

        return $this->serviceTargetPlan($graph, $target, $parameter->getName());
    }

    /** @return ServiceArgument|string */
    private function methodValuePlan(DefinitionGraph $graph, mixed $value, string $parameter): array|string
    {
        if (!is_string($value)) {
            if (!$this->isExportable($value)) {
                return "method parameter '{$parameter}' has a non-exportable #[Inject] value";
            }

            return ['kind' => 'value', 'code' => var_export($value, true)];
        }

        if ($graph->hasDefinition($value) || class_exists($value) || interface_exists($value)) {
            $service = $this->serviceTargetPlan($graph, $value, $parameter);
            if ($service !== null) {
                return $service;
            }
        }
        if (function_exists($value)) {
            return "method parameter '{$parameter}' injects a runtime function";
        }

        return ['kind' => 'value', 'code' => var_export($value, true)];
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

    /** @return ServiceArgument|string|null */
    private function serviceTargetPlan(DefinitionGraph $graph, string $target, string $parameter): array|string|null
    {
        if ($graph->hasDefinition($target)) {
            return ['kind' => 'service', 'id' => $target];
        }
        if (function_exists($target)) {
            return "method parameter '{$parameter}' injects a runtime function";
        }
        if (!class_exists($target) && !interface_exists($target)) {
            return null;
        }

        $concrete = $graph->environmentConcrete($target) ?? $target;
        if (!class_exists($concrete)) {
            return "method parameter '{$parameter}' injects an unresolved interface '$target'";
        }

        return ['kind' => 'service', 'id' => $concrete];
    }
}
