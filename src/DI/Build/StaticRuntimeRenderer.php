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
 * @internal
 */
final class StaticRuntimeRenderer
{
    /**
     * @param array<string, ServicePlan> $plans
     * @param array<string, int> $slots
     */
    public function render(DefinitionGraph $graph, array $plans, array $slots): string
    {
        $source = "<?php\n\ndeclare(strict_types=1);\n\n";
        $source .= "use Infocyph\\InterMix\\DI\\ProductionContainer;\n\n";
        $source .= "return new class extends ProductionContainer\n{\n";
        $source .= $this->renderAliasSingletonProperties($plans);
        $source .= $this->renderFactorySingletonProperties($plans);
        $source .= $this->renderInvocationSingletonProperties($plans);
        $source .= $this->renderSingletonProperties($plans, $slots);
        $source .= $this->renderGet($plans, $slots);
        $source .= $this->renderHas($plans);
        $source .= $this->renderSlotMap($slots);
        $source .= $this->renderCompiledIds($plans);
        $source .= $this->renderDefinitionMap($graph, $plans);
        $source .= $this->renderFreshMap($plans, $slots);
        $source .= $this->renderFreshMethods($plans, $slots);
        $source .= $this->renderCompiledSingletonValues($plans, $slots);
        $source .= $this->renderTags($graph, $plans);
        $source .= $this->renderServiceMethods($plans, $slots);

        return rtrim($source) . "\n};\n";
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
     * @param ClassPlan $plan
     * @param array<string, int> $slots
     */
    private function classConstruction(array $plan, array $slots): string
    {
        $arguments = [];
        foreach ($plan['arguments'] as $argument) {
            $arguments[] = $this->argumentExpression($argument, $slots);
        }

        return 'new \\' . ltrim($plan['class'], '\\') . '(' . implode(', ', $arguments) . ')';
    }

    /**
     * @param ClassPlan $plan
     * @param array<string, int> $slots
     */
    private function classInitializationStatements(array $plan, array $slots): string
    {
        $source = '        $instance = ' . $this->classConstruction($plan, $slots) . ";\n";
        foreach ($plan['properties'] as $property) {
            $value = $this->argumentExpression($property['argument'], $slots);
            if ($property['static']) {
                $class = '\\' . ltrim($property['declaring'], '\\');
                $source .= '        ' . $class . '::$' . $property['property'] . " = {$value};\n";
            } else {
                $source .= '        $instance->' . $property['property'] . " = {$value};\n";
            }
        }

        return $source;
    }

    /**
     * @param ClassPlan $plan
     * @param array<string, int> $slots
     */
    private function classServiceStatements(array $plan, array $slots): string
    {
        $source = $this->classInitializationStatements($plan, $slots);
        $postMethod = $plan['postMethod'];
        if (!is_array($postMethod)) {
            return $source;
        }

        $arguments = [];
        foreach ($postMethod['arguments'] as $argument) {
            $arguments[] = $this->argumentExpression($argument, $slots);
        }

        return $source
            . "\n        "
            . '$instance->'
            . $postMethod['method']
            . '('
            . implode(', ', $arguments)
            . ");\n";
    }

    /**
     * @param FactoryPlan $plan
     * @param array<string, int> $slots
     */
    private function factoryExpression(array $plan, array $slots): string
    {
        $arguments = [];
        foreach ($plan['arguments'] as $argument) {
            $arguments[] = $this->argumentExpression($argument, $slots);
        }

        $class = '\\' . ltrim($plan['class'], '\\');
        if ($plan['method'] === null) {
            return 'new ' . $class . '(' . implode(', ', $arguments) . ')';
        }

        return $class . '::' . $plan['method'] . '(' . implode(', ', $arguments) . ')';
    }

    /**
     * @param InvocationPlan $plan
     * @param array<string, int> $slots
     */
    private function invocationStatements(array $plan, array $slots): string
    {
        $constructorArguments = [];
        foreach ($plan['arguments'] as $argument) {
            $constructorArguments[] = $this->argumentExpression($argument, $slots);
        }

        $class = '\\' . ltrim($plan['class'], '\\');
        $source = '        $instance = new ' . $class . '(' . implode(', ', $constructorArguments) . ");\n";
        foreach ($plan['properties'] as $property) {
            $value = $this->argumentExpression($property['argument'], $slots);
            if ($property['static']) {
                $declaring = '\\' . ltrim($property['declaring'], '\\');
                $source .= '        ' . $declaring . '::$' . $property['property'] . " = {$value};\n";
            } else {
                $source .= '        $instance->' . $property['property'] . " = {$value};\n";
            }
        }

        $methodArguments = [];
        foreach ($plan['invocation']['arguments'] as $argument) {
            $methodArguments[] = $this->argumentExpression($argument, $slots);
        }
        $source .= '        $result = $instance->' . $plan['invocation']['method']
            . '(' . implode(', ', $methodArguments) . ");\n";

        return $source;
    }

    /**
     * @param AliasPlan $plan
     * @param array<string, int> $slots
     */
    private function renderAliasMethod(int $slot, array $plan, array $slots): string
    {
        $target = '$this->s' . $slots[$plan['target']] . '()';
        $source = "    private function s{$slot}(): mixed\n    {\n";
        $source .= $this->renderSeedGuard($slot);

        if ($plan['lifetime'] === LifetimeEnum::Scoped) {
            $source .= "        if (array_key_exists({$slot}, \$this->scope->resolved)) {\n";
            $source .= "            return \$this->scope->resolved[{$slot}];\n";
            $source .= "        }\n\n";
            $source .= "        return \$this->scope->resolved[{$slot}] = {$target};\n";
        } elseif ($plan['lifetime'] === LifetimeEnum::Singleton) {
            $source .= "        if (array_key_exists({$slot}, \$this->aliasSingletons)) {\n";
            $source .= "            return \$this->aliasSingletons[{$slot}];\n";
            $source .= "        }\n\n";
            $source .= "        return \$this->aliasSingletons[{$slot}] = {$target};\n";
        } else {
            $source .= "        return {$target};\n";
        }

        return $source . "    }\n\n";
    }

    /** @param array<string, ServicePlan> $plans */
    private function renderAliasSingletonProperties(array $plans): string
    {
        foreach ($plans as $plan) {
            if ($plan['kind'] === 'alias' && $plan['lifetime'] === LifetimeEnum::Singleton) {
                return "    /** @var array<int, mixed> */\n    private array \$aliasSingletons = [];\n\n";
            }
        }

        return '';
    }

    /**
     * @param ClassPlan $plan
     * @param array<string, int> $slots
     */
    private function renderClassMethod(int $slot, array $plan, array $slots): string
    {
        $source = "    private function s{$slot}(): mixed\n    {\n";
        $source .= $this->renderSeedGuard($slot);
        $hasSetup = $plan['properties'] !== [] || $plan['postMethod'] !== null;
        $construction = $hasSetup ? null : $this->classConstruction($plan, $slots);

        if ($plan['lifetime'] === LifetimeEnum::Scoped) {
            $source .= "        if (array_key_exists({$slot}, \$this->scope->resolved)) {\n";
            $source .= "            return \$this->scope->resolved[{$slot}];\n";
            $source .= "        }\n\n";
            if ($hasSetup) {
                $source .= $this->classServiceStatements($plan, $slots);
                $source .= "\n        return \$this->scope->resolved[{$slot}] = \$instance;\n";
            } else {
                $source .= "        return \$this->scope->resolved[{$slot}] = {$construction};\n";
            }
        } elseif ($plan['lifetime'] === LifetimeEnum::Singleton) {
            if ($hasSetup) {
                $source .= "        if (\$this->v{$slot} !== null) {\n";
                $source .= "            return \$this->v{$slot};\n";
                $source .= "        }\n\n";
                $source .= $this->classServiceStatements($plan, $slots);
                $source .= "\n        return \$this->v{$slot} = \$instance;\n";
            } else {
                $source .= "        return \$this->v{$slot} ??= {$construction};\n";
            }
        } elseif ($hasSetup) {
            $source .= $this->classServiceStatements($plan, $slots);
            $source .= "\n        return \$instance;\n";
        } else {
            $source .= "        return {$construction};\n";
        }

        return $source . "    }\n\n";
    }

    /** @param array<string, ServicePlan> $plans */
    private function renderCompiledIds(array $plans): string
    {
        return "    protected function compiledIds(): array\n"
            . "    {\n"
            . '        return ' . var_export(array_keys($plans), true) . ";\n"
            . "    }\n\n";
    }

    /**
     * @param array<string, ServicePlan> $plans
     * @param array<string, int> $slots
     */
    private function renderCompiledSingletonValues(array $plans, array $slots): string
    {
        $entries = [];
        foreach ($plans as $id => $plan) {
            if ($plan['lifetime'] !== LifetimeEnum::Singleton) {
                continue;
            }
            $slot = $slots[$id];
            if ($plan['kind'] === 'class') {
                $entries[] = [
                    'guard' => "\$this->v{$slot} !== null",
                    'id' => $id,
                    'value' => "\$this->v{$slot}",
                ];
            } elseif ($plan['kind'] === 'alias') {
                $entries[] = [
                    'guard' => "array_key_exists({$slot}, \$this->aliasSingletons)",
                    'id' => $id,
                    'value' => "\$this->aliasSingletons[{$slot}]",
                ];
            } elseif ($plan['kind'] === 'factory') {
                $entries[] = [
                    'guard' => "array_key_exists({$slot}, \$this->factorySingletons)",
                    'id' => $id,
                    'value' => "\$this->factorySingletons[{$slot}]",
                ];
            } elseif ($plan['kind'] === 'invocation') {
                $entries[] = [
                    'guard' => "array_key_exists({$slot}, \$this->invocationSingletons)",
                    'id' => $id,
                    'value' => "\$this->invocationSingletons[{$slot}]",
                ];
            }
        }
        if ($entries === []) {
            return '';
        }

        $source = "    protected function compiledSingletonValues(): array\n    {\n        \$values = [];\n";
        foreach ($entries as $entry) {
            $id = var_export($entry['id'], true);
            $source .= "        if ({$entry['guard']}) {\n";
            $source .= "            \$values[{$id}] = {$entry['value']};\n";
            $source .= "        }\n";
        }
        $source .= "\n        return \$values;\n";

        return $source . "    }\n\n";
    }

    /** @param array<string, ServicePlan> $plans */
    private function renderDefinitionMap(DefinitionGraph $graph, array $plans): string
    {
        $definitions = [];
        foreach (array_keys($plans) as $id) {
            if ($graph->hasDefinition($id)) {
                $definitions[] = $id;
            }
        }
        if ($definitions === []) {
            return '';
        }
        $ids = implode(', ', array_map(static fn(string $id): string => var_export($id, true), $definitions));

        return "    protected function isCompiledDefinition(string \$id): bool\n"
            . "    {\n"
            . "        return match (\$id) {\n"
            . "            {$ids} => true,\n"
            . "            default => false,\n"
            . "        };\n"
            . "    }\n\n";
    }

    /**
     * @param FactoryPlan $plan
     * @param array<string, int> $slots
     */
    private function renderFactoryMethod(int $slot, array $plan, array $slots): string
    {
        $expression = $this->factoryExpression($plan, $slots);
        $source = "    private function s{$slot}(): mixed\n    {\n";
        $source .= $this->renderSeedGuard($slot);

        if ($plan['lifetime'] === LifetimeEnum::Scoped) {
            $source .= "        if (array_key_exists({$slot}, \$this->scope->resolved)) {\n";
            $source .= "            return \$this->scope->resolved[{$slot}];\n";
            $source .= "        }\n\n";
            $source .= "        return \$this->scope->resolved[{$slot}] = {$expression};\n";
        } elseif ($plan['lifetime'] === LifetimeEnum::Singleton) {
            $source .= "        if (array_key_exists({$slot}, \$this->factorySingletons)) {\n";
            $source .= "            return \$this->factorySingletons[{$slot}];\n";
            $source .= "        }\n\n";
            $source .= "        return \$this->factorySingletons[{$slot}] = {$expression};\n";
        } else {
            $source .= "        return {$expression};\n";
        }

        return $source . "    }\n\n";
    }

    /** @param array<string, ServicePlan> $plans */
    private function renderFactorySingletonProperties(array $plans): string
    {
        foreach ($plans as $plan) {
            if ($plan['kind'] === 'factory' && $plan['lifetime'] === LifetimeEnum::Singleton) {
                return "    /** @var array<int, mixed> */\n    private array \$factorySingletons = [];\n\n";
            }
        }

        return '';
    }

    /**
     * @param array<string, ServicePlan> $plans
     * @param array<string, int> $slots
     */
    private function renderFreshMap(array $plans, array $slots): string
    {
        $fresh = [];
        foreach ($plans as $id => $plan) {
            if ($plan['kind'] !== 'class' || $id !== $plan['class']) {
                continue;
            }
            $fresh[$id] = $plan['properties'] === []
                ? $this->classConstruction($plan, $slots)
                : '$this->f' . $slots[$id] . '()';
        }
        if ($fresh === []) {
            return '';
        }

        $source = "    protected function freshCompiled(string \$class): ?object\n    {\n        return match (\$class) {\n";
        foreach ($fresh as $class => $construction) {
            $source .= '            ' . var_export($class, true) . " => {$construction},\n";
        }
        $source .= "            default => null,\n";

        return $source . "        };\n    }\n\n";
    }

    /**
     * @param array<string, ServicePlan> $plans
     * @param array<string, int> $slots
     */
    private function renderFreshMethods(array $plans, array $slots): string
    {
        $source = '';
        foreach ($plans as $id => $plan) {
            if ($plan['kind'] !== 'class' || $id !== $plan['class'] || $plan['properties'] === []) {
                continue;
            }
            $slot = $slots[$id];
            $source .= "    private function f{$slot}(): object\n    {\n";
            $source .= $this->classInitializationStatements($plan, $slots);
            $source .= "\n        return \$instance;\n    }\n\n";
        }

        return $source;
    }

    /**
     * @param array<string, ServicePlan> $plans
     * @param array<string, int> $slots
     */
    private function renderGet(array $plans, array $slots): string
    {
        $source = "    public function get(string \$id): mixed\n    {\n";
        $source .= "        if (\$this->isDeoptimized()) {\n            return \$this->fallbackGet(\$id);\n        }\n\n";
        $source .= "        return match (\$id) {\n";
        foreach ($plans as $id => $_plan) {
            $source .= '            ' . var_export($id, true) . ' => $this->s' . $slots[$id] . "(),\n";
        }
        $source .= "            default => \$this->fallbackGet(\$id),\n";

        return $source . "        };\n    }\n\n";
    }

    /** @param array<string, ServicePlan> $plans */
    private function renderHas(array $plans): string
    {
        $source = "    public function has(string \$id): bool\n    {\n";
        $source .= "        if (\$this->isDeoptimized()) {\n            return \$this->fallbackHas(\$id);\n        }\n\n";
        $source .= "        return match (\$id) {\n";
        if ($plans !== []) {
            $ids = implode(', ', array_map(static fn(string $id): string => var_export($id, true), array_keys($plans)));
            $source .= "            {$ids} => true,\n";
        }
        $source .= "            default => \$this->fallbackHas(\$id),\n";

        return $source . "        };\n    }\n\n";
    }

    /**
     * @param InvocationPlan $plan
     * @param array<string, int> $slots
     */
    private function renderInvocationMethod(int $slot, array $plan, array $slots): string
    {
        $source = "    private function s{$slot}(): mixed\n    {\n";
        $source .= $this->renderSeedGuard($slot);

        if ($plan['lifetime'] === LifetimeEnum::Scoped) {
            $source .= "        if (array_key_exists({$slot}, \$this->scope->resolved)) {\n";
            $source .= "            return \$this->scope->resolved[{$slot}];\n";
            $source .= "        }\n\n";
            $source .= $this->invocationStatements($plan, $slots);
            $source .= "\n        return \$this->scope->resolved[{$slot}] = \$result;\n";
        } elseif ($plan['lifetime'] === LifetimeEnum::Singleton) {
            $source .= "        if (array_key_exists({$slot}, \$this->invocationSingletons)) {\n";
            $source .= "            return \$this->invocationSingletons[{$slot}];\n";
            $source .= "        }\n\n";
            $source .= $this->invocationStatements($plan, $slots);
            $source .= "\n        return \$this->invocationSingletons[{$slot}] = \$result;\n";
        } else {
            $source .= $this->invocationStatements($plan, $slots);
            $source .= "\n        return \$result;\n";
        }

        return $source . "    }\n\n";
    }

    /** @param array<string, ServicePlan> $plans */
    private function renderInvocationSingletonProperties(array $plans): string
    {
        foreach ($plans as $plan) {
            if ($plan['kind'] === 'invocation' && $plan['lifetime'] === LifetimeEnum::Singleton) {
                return "    /** @var array<int, mixed> */\n    private array \$invocationSingletons = [];\n\n";
            }
        }

        return '';
    }

    private function renderSeedGuard(int $slot): string
    {
        return "        if (\$this->scope->parent !== null && array_key_exists({$slot}, \$this->scope->seeds)) {\n"
            . "            return \$this->scope->seeds[{$slot}];\n"
            . "        }\n\n";
    }

    /**
     * @param array<string, ServicePlan> $plans
     * @param array<string, int> $slots
     */
    private function renderServiceMethods(array $plans, array $slots): string
    {
        $source = '';
        foreach ($plans as $id => $plan) {
            $source .= match ($plan['kind']) {
                'alias' => $this->renderAliasMethod($slots[$id], $plan, $slots),
                'class' => $this->renderClassMethod($slots[$id], $plan, $slots),
                'factory' => $this->renderFactoryMethod($slots[$id], $plan, $slots),
                'invocation' => $this->renderInvocationMethod($slots[$id], $plan, $slots),
                'value' => $this->renderValueMethod($slots[$id], $plan),
            };
        }

        return $source;
    }

    /**
     * @param array<string, ServicePlan> $plans
     * @param array<string, int> $slots
     */
    private function renderSingletonProperties(array $plans, array $slots): string
    {
        $source = '';
        foreach ($plans as $id => $plan) {
            if ($plan['kind'] !== 'class' || $plan['lifetime'] !== LifetimeEnum::Singleton) {
                continue;
            }
            $class = '\\' . ltrim($plan['class'], '\\');
            $source .= "    private ?{$class} \$v{$slots[$id]} = null;\n";
        }

        return $source === '' ? '' : $source . "\n";
    }

    /** @param array<string, int> $slots */
    private function renderSlotMap(array $slots): string
    {
        $source = "    protected function slotFor(string \$id): ?int\n    {\n        return match (\$id) {\n";
        foreach ($slots as $id => $slot) {
            $source .= '            ' . var_export($id, true) . " => {$slot},\n";
        }
        $source .= "            default => null,\n";

        return $source . "        };\n    }\n\n";
    }

    /** @param array<string, ServicePlan> $plans */
    private function renderTags(DefinitionGraph $graph, array $plans): string
    {
        $tags = [];
        foreach (array_keys($plans) as $id) {
            foreach ($graph->definitionMetaFor($id)['tags'] as $tag) {
                $tags[$tag][] = $id;
            }
        }
        if ($tags === []) {
            return '';
        }
        ksort($tags, SORT_STRING);

        $source = "    protected function taggedIds(string \$tag): array\n    {\n        return match (\$tag) {\n";
        foreach ($tags as $tag => $ids) {
            $source .= '            ' . var_export($tag, true) . ' => ' . var_export($ids, true) . ",\n";
        }
        $source .= "            default => [],\n";

        return $source . "        };\n    }\n\n";
    }

    /** @param ValuePlan $plan */
    private function renderValueMethod(int $slot, array $plan): string
    {
        return "    private function s{$slot}(): mixed\n"
            . "    {\n"
            . $this->renderSeedGuard($slot)
            . '        return ' . $plan['code'] . ";\n"
            . "    }\n\n";
    }
}
