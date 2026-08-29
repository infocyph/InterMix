<?php

declare(strict_types=1);

namespace Infocyph\InterMix\DI\Build;

use Infocyph\InterMix\DI\Support\LifetimeEnum;

/**
 * @phpstan-type ServiceArgument array{kind: 'service', id: string}|array{kind: 'value', code: string}
 * @phpstan-type PropertyPlan array{declaring: class-string, property: string, static: bool, argument: ServiceArgument}
 * @phpstan-type MethodPlan array{method: string, arguments: list<ServiceArgument>, dependencies: list<string>}
 * @phpstan-type AliasPlan array{kind: 'alias', target: string, lifetime: LifetimeEnum, arguments: list<ServiceArgument>, properties: list<PropertyPlan>, dependencies: list<string>}
 * @phpstan-type ClassPlan array{kind: 'class', class: class-string, lifetime: LifetimeEnum, arguments: list<ServiceArgument>, properties: list<PropertyPlan>, postMethod: MethodPlan|null, dependencies: list<string>}
 * @phpstan-type FactoryPlan array{kind: 'factory', class: class-string, method: string|null, lifetime: LifetimeEnum, arguments: list<ServiceArgument>, properties: list<PropertyPlan>, dependencies: list<string>}
 * @phpstan-type InvocationPlan array{kind: 'invocation', class: class-string, lifetime: LifetimeEnum, arguments: list<ServiceArgument>, properties: list<PropertyPlan>, invocation: MethodPlan, dependencies: list<string>}
 * @phpstan-type ValuePlan array{kind: 'value', code: string, lifetime: LifetimeEnum, arguments: list<ServiceArgument>, properties: list<PropertyPlan>, dependencies: list<string>}
 * @phpstan-type ServicePlan AliasPlan|ClassPlan|FactoryPlan|InvocationPlan|ValuePlan
 * @phpstan-type FreshInvocationPlan array{class: class-string, arguments: list<ServiceArgument>, properties: list<PropertyPlan>, invocation: MethodPlan}
 * @internal
 */
final class StaticFreshInvocationRenderer
{
    /**
     * @param array<string, ServicePlan> $plans
     * @param array<string, int> $slots
     */
    public function render(array $plans, array $slots): string
    {
        $recipes = $this->recipes($plans);
        if ($recipes === []) {
            return '';
        }

        $grouped = [];
        $methods = '';
        $index = 0;
        foreach ($recipes as $recipe) {
            $class = $recipe['class'];
            $method = $recipe['invocation']['method'];
            $grouped[$class][$method] = $index;
            $methods .= $this->renderRecipeMethod($index, $recipe, $slots);
            ++$index;
        }
        ksort($grouped, SORT_STRING);
        foreach ($grouped as &$byMethod) {
            ksort($byMethod, SORT_STRING);
        }
        unset($byMethod);

        return $this->renderDispatch($grouped) . $methods;
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

    /**
     * @param array<string, array<string, int>> $grouped
     */
    private function renderDispatch(array $grouped): string
    {
        $source = "    protected function freshCompiledInvocation(\n";
        $source .= "        string \$class,\n";
        $source .= "        string \$method,\n";
        $source .= "        mixed &\$result,\n";
        $source .= "    ): bool {\n";
        $source .= "        return match (\$class) {\n";
        foreach ($grouped as $class => $methods) {
            $source .= '            ' . var_export($class, true) . " => match (\$method) {\n";
            foreach ($methods as $method => $index) {
                $source .= '                ' . var_export($method, true)
                    . " => \$this->fi{$index}(\$result),\n";
            }
            $source .= "                default => false,\n";
            $source .= "            },\n";
        }
        $source .= "            default => false,\n";
        $source .= "        };\n";

        return $source . "    }\n\n";
    }

    /**
     * @param FreshInvocationPlan $recipe
     * @param array<string, int> $slots
     */
    private function renderRecipeMethod(int $index, array $recipe, array $slots): string
    {
        $constructorArguments = [];
        foreach ($recipe['arguments'] as $argument) {
            $constructorArguments[] = $this->argumentExpression($argument, $slots);
        }

        $class = '\\' . ltrim($recipe['class'], '\\');
        $source = "    private function fi{$index}(mixed &\$result): bool\n    {\n";
        $source .= '        $instance = new ' . $class . '(' . implode(', ', $constructorArguments) . ");\n";
        foreach ($recipe['properties'] as $property) {
            $value = $this->argumentExpression($property['argument'], $slots);
            if ($property['static']) {
                $declaring = '\\' . ltrim($property['declaring'], '\\');
                $source .= '        ' . $declaring . '::$' . $property['property'] . " = {$value};\n";
            } else {
                $source .= '        $instance->' . $property['property'] . " = {$value};\n";
            }
        }

        $methodArguments = [];
        foreach ($recipe['invocation']['arguments'] as $argument) {
            $methodArguments[] = $this->argumentExpression($argument, $slots);
        }
        $source .= '        $result = $instance->' . $recipe['invocation']['method']
            . '(' . implode(', ', $methodArguments) . ");\n";
        $source .= "\n        return true;\n";

        return $source . "    }\n\n";
    }

    /**
     * @param array<string, ServicePlan> $plans
     * @return array<string, FreshInvocationPlan>
     */
    private function recipes(array $plans): array
    {
        $recipes = [];
        foreach ($plans as $plan) {
            $recipe = $this->toRecipe($plan);
            if ($recipe === null) {
                continue;
            }

            $key = $recipe['class'] . "\0" . $recipe['invocation']['method'];
            $recipes[$key] ??= $recipe;
        }
        ksort($recipes, SORT_STRING);

        return $recipes;
    }

    /**
     * @param ServicePlan $plan
     * @return FreshInvocationPlan|null
     */
    private function toRecipe(array $plan): ?array
    {
        if ($plan['kind'] === 'invocation') {
            return [
                'class' => $plan['class'],
                'arguments' => $plan['arguments'],
                'properties' => $plan['properties'],
                'invocation' => $plan['invocation'],
            ];
        }
        if ($plan['kind'] !== 'class' || !is_array($plan['postMethod'])) {
            return null;
        }

        return [
            'class' => $plan['class'],
            'arguments' => $plan['arguments'],
            'properties' => $plan['properties'],
            'invocation' => $plan['postMethod'],
        ];
    }
}
