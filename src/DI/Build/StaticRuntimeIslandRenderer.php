<?php

declare(strict_types=1);

namespace Infocyph\InterMix\DI\Build;

/**
 * @phpstan-type ServiceArgument array{kind: 'service', id: string}|array{kind: 'value', code: string}
 * @phpstan-type PropertyPlan array{declaring: class-string, property: string, static: bool, argument: ServiceArgument|null, runtime?: 'attribute'|'assign'|'registered'}
 * @phpstan-type MethodPlan array{method: string, arguments: list<ServiceArgument>, dependencies: list<string>, runtime?: bool}
 * @internal
 */
final class StaticRuntimeIslandRenderer
{
    /**
     * @param MethodPlan $method
     * @param array<string, int> $slots
     */
    public function methodExpression(
        string $class,
        array $method,
        array $slots,
        string $instance = '$instance',
    ): string {
        if ($method['runtime'] ?? false) {
            return '$this->invokeCompiledRuntimeMethod('
                . $instance . ', '
                . var_export($class, true) . ', '
                . var_export($method['method'], true)
                . ')';
        }

        $arguments = [];
        foreach ($method['arguments'] as $argument) {
            $arguments[] = $this->argumentExpression($argument, $slots);
        }

        return $instance . '->' . $method['method'] . '(' . implode(', ', $arguments) . ')';
    }

    /**
     * @param list<PropertyPlan> $properties
     * @param array<string, int> $slots
     */
    public function propertyStatements(
        array $properties,
        array $slots,
        string $instance = '$instance',
    ): string {
        $source = '';
        foreach ($properties as $property) {
            $runtime = $property['runtime'] ?? null;
            if ($runtime === 'attribute' || $runtime === 'registered') {
                $source .= '        $this->applyCompiledRuntimePropertyAttribute('
                    . $instance . ', '
                    . var_export($property['declaring'], true) . ', '
                    . var_export($property['property'], true)
                    . ");\n";

                continue;
            }

            $argument = $property['argument'];
            if (!is_array($argument)) {
                continue;
            }
            $value = $this->argumentExpression($argument, $slots);
            if ($runtime === 'assign') {
                $source .= '        $this->assignCompiledRuntimeProperty('
                    . $instance . ', '
                    . var_export($property['declaring'], true) . ', '
                    . var_export($property['property'], true) . ', '
                    . $value
                    . ");\n";

                continue;
            }

            if ($property['static']) {
                $class = '\\' . ltrim($property['declaring'], '\\');
                $source .= '        ' . $class . '::$' . $property['property'] . " = {$value};\n";
            } else {
                $source .= '        ' . $instance . '->' . $property['property'] . " = {$value};\n";
            }
        }

        return $source;
    }

    /**
     * @param ServiceArgument $argument
     * @param array<string, int> $slots
     */
    private function argumentExpression(array $argument, array $slots): string
    {
        return $argument['kind'] === 'service'
            ? '$this->s' . $slots[$argument['id']] . '()'
            : $argument['code'];
    }
}
