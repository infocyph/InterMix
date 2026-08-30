<?php

declare(strict_types=1);

namespace Infocyph\InterMix\DI\Build;

use Infocyph\InterMix\DI\Support\LifetimeEnum;

/**
 * @phpstan-type ServiceArgument array{kind: 'service', id: string}|array{kind: 'value', code: string}
 * @phpstan-type PropertyPlan array{declaring: class-string, property: string, static: bool, argument: ServiceArgument|null, runtime?: 'attribute'|'assign'}
 * @phpstan-type MethodPlan array{method: string, arguments: list<ServiceArgument>, dependencies: list<string>, runtime?: bool}
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
        $invocationRenderer = new StaticInvocationRenderer();
        $lifecycleRenderer = new StaticLifecycleHookRenderer();
        $returnRenderer = new StaticReturnRenderer();
        $source = "<?php\n\ndeclare(strict_types=1);\n\n";
        $source .= "use Infocyph\\InterMix\\DI\\ProductionContainer;\n\n";
        $source .= "return new class extends ProductionContainer\n{\n";
        $source .= $this->renderAliasSingletonProperties($plans);
        $source .= $this->renderFactorySingletonProperties($plans);
        $source .= $invocationRenderer->renderSingletonProperties($plans);
        $source .= $returnRenderer->renderProperties($plans);
        $source .= $lifecycleRenderer->renderValueSingletonProperties($graph, $plans);
        $source .= $this->renderSingletonProperties($plans, $slots);
        $source .= $this->renderGet($plans, $slots);
        $source .= $this->renderHas($plans);
        $source .= $this->renderSlotMap($slots);
        $source .= $this->renderCompiledIds($plans);
        $source .= $returnRenderer->renderDispatch($plans, $slots);
        $source .= $this->renderDefinitionMap($graph, $plans);
        $source .= $this->renderFreshMap($plans, $slots);
        $source .= $this->renderFreshMethods($plans, $slots);
        $source .= new StaticFreshInvocationRenderer()->render($plans, $slots);
        $source .= $this->renderCompiledSingletonValues($graph, $plans, $slots, $lifecycleRenderer);
        $source .= $this->renderTags($graph, $plans);
        $source .= $lifecycleRenderer->renderScopeLeaveHooks($graph);
        $source .= $this->renderServiceMethods(
            $graph,
            $plans,
            $slots,
            $invocationRenderer,
            $lifecycleRenderer,
        );

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
        return '        $instance = ' . $this->classConstruction($plan, $slots) . ";\n"
            . new StaticRuntimeIslandRenderer()->propertyStatements($plan['properties'], $slots);
    }

    /**
     * @param ClassPlan $plan
     * @param array<string, int> $slots
     */
    private function classServiceStatements(array $plan, array $slots, int $slot): string
    {
        $source = $this->classInitializationStatements($plan, $slots);
        $postMethod = $plan['postMethod'];
        if (!is_array($postMethod)) {
            return $source;
        }

        return $source . new StaticReturnRenderer()->invocationStatement(
            $plan,
            $postMethod,
            $slots,
            $slot,
        );
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
     * @param AliasPlan $plan
     * @param array<string, int> $slots
     */
    private function renderAliasMethod(
        DefinitionGraph $graph,
        string $id,
        int $slot,
        array $plan,
        array $slots,
        StaticLifecycleHookRenderer $lifecycleRenderer,
    ): string {
        $target = '$this->s' . $slots[$plan['target']] . '()';
        if ($lifecycleRenderer->hasResolutionHooks($graph, $id)) {
            return $lifecycleRenderer->renderExpressionMethod(
                $graph,
                $slot,
                $id,
                $plan['lifetime'],
                $target,
                'aliasSingletons',
            );
        }

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
    private function renderClassMethod(
        DefinitionGraph $graph,
        string $id,
        int $slot,
        array $plan,
        array $slots,
        StaticLifecycleHookRenderer $lifecycleRenderer,
    ): string {
        if ($lifecycleRenderer->hasResolutionHooks($graph, $id)) {
            return $lifecycleRenderer->renderClassMethod(
                $graph,
                $slot,
                $id,
                $plan['lifetime'],
                $this->classServiceStatements($plan, $slots, $slot),
            );
        }

        $source = "    private function s{$slot}(): mixed\n    {\n";
        $source .= $this->renderSeedGuard($slot);
        $hasSetup = $plan['properties'] !== [] || $plan['postMethod'] !== null;
        $construction = $hasSetup ? null : $this->classConstruction($plan, $slots);

        if ($plan['lifetime'] === LifetimeEnum::Scoped) {
            $source .= "        if (isset(\$this->scope->resolved[{$slot}])) {\n";
            $source .= "            return \$this->scope->resolved[{$slot}];\n";
            $source .= "        }\n\n";
            if ($hasSetup) {
                $source .= $this->classServiceStatements($plan, $slots, $slot);
                $source .= "\n        return \$this->scope->resolved[{$slot}] = \$instance;\n";
            } else {
                $source .= "        return \$this->scope->resolved[{$slot}] = {$construction};\n";
            }
        } elseif ($plan['lifetime'] === LifetimeEnum::Singleton) {
            if ($hasSetup) {
                $source .= "        if (\$this->v{$slot} !== null) {\n";
                $source .= "            return \$this->v{$slot};\n";
                $source .= "        }\n\n";
                $source .= $this->classServiceStatements($plan, $slots, $slot);
                $source .= "\n        return \$this->v{$slot} = \$instance;\n";
            } else {
                $source .= "        return \$this->v{$slot} ??= {$construction};\n";
            }
        } elseif ($hasSetup) {
            $source .= $this->classServiceStatements($plan, $slots, $slot);
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
    private function renderCompiledSingletonValues(
        DefinitionGraph $graph,
        array $plans,
        array $slots,
        StaticLifecycleHookRenderer $lifecycleRenderer,
    ): string {
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
            } elseif ($lifecycleRenderer->hasResolutionHooks($graph, $id)) {
                $entries[] = [
                    'guard' => "array_key_exists({$slot}, \$this->hookedValueSingletons)",
                    'id' => $id,
                    'value' => "\$this->hookedValueSingletons[{$slot}]",
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
    private function renderFactoryMethod(
        DefinitionGraph $graph,
        string $id,
        int $slot,
        array $plan,
        array $slots,
        StaticLifecycleHookRenderer $lifecycleRenderer,
    ): string {
        $expression = $this->factoryExpression($plan, $slots);
        if ($lifecycleRenderer->hasResolutionHooks($graph, $id)) {
            return $lifecycleRenderer->renderExpressionMethod(
                $graph,
                $slot,
                $id,
                $plan['lifetime'],
                $expression,
                'factorySingletons',
            );
        }

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

    private function renderSeedGuard(int $slot): string
    {
        return "        if (\$this->scope->hasSeeds && array_key_exists({$slot}, \$this->scope->seeds)) {\n"
            . "            return \$this->scope->seeds[{$slot}];\n"
            . "        }\n\n";
    }

    /**
     * @param array<string, ServicePlan> $plans
     * @param array<string, int> $slots
     */
    private function renderServiceMethods(
        DefinitionGraph $graph,
        array $plans,
        array $slots,
        StaticInvocationRenderer $invocationRenderer,
        StaticLifecycleHookRenderer $lifecycleRenderer,
    ): string {
        $source = '';
        foreach ($plans as $id => $plan) {
            $source .= match ($plan['kind']) {
                'alias' => $this->renderAliasMethod(
                    $graph,
                    $id,
                    $slots[$id],
                    $plan,
                    $slots,
                    $lifecycleRenderer,
                ),
                'class' => $this->renderClassMethod(
                    $graph,
                    $id,
                    $slots[$id],
                    $plan,
                    $slots,
                    $lifecycleRenderer,
                ),
                'factory' => $this->renderFactoryMethod(
                    $graph,
                    $id,
                    $slots[$id],
                    $plan,
                    $slots,
                    $lifecycleRenderer,
                ),
                'invocation' => $invocationRenderer->renderMethod(
                    $slots[$id],
                    $plan,
                    $slots,
                    $id,
                    $graph->hasResolvingHook($id),
                    $graph->hasResolvedHook($id),
                ),
                'value' => $this->renderValueMethod(
                    $graph,
                    $id,
                    $slots[$id],
                    $plan,
                    $lifecycleRenderer,
                ),
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
    private function renderValueMethod(
        DefinitionGraph $graph,
        string $id,
        int $slot,
        array $plan,
        StaticLifecycleHookRenderer $lifecycleRenderer,
    ): string {
        if ($lifecycleRenderer->hasResolutionHooks($graph, $id)) {
            return $lifecycleRenderer->renderExpressionMethod(
                $graph,
                $slot,
                $id,
                $plan['lifetime'],
                $plan['code'],
                'hookedValueSingletons',
            );
        }

        return "    private function s{$slot}(): mixed\n"
            . "    {\n"
            . $this->renderSeedGuard($slot)
            . '        return ' . $plan['code'] . ";\n"
            . "    }\n\n";
    }
}
