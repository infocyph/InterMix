<?php

declare(strict_types=1);

namespace Infocyph\InterMix\DI\Build;

use Infocyph\InterMix\DI\Support\LifetimeEnum;

/**
 * @phpstan-type ServiceArgument array{kind: 'service', id: string}|array{kind: 'value', code: string}
 * @phpstan-type ClassPlan array{kind: 'class', class: class-string, lifetime: LifetimeEnum, arguments: list<ServiceArgument>, dependencies: list<string>}
 * @phpstan-type ValuePlan array{kind: 'value', code: string, lifetime: LifetimeEnum, arguments: list<ServiceArgument>, dependencies: list<string>}
 * @phpstan-type ServicePlan ClassPlan|ValuePlan
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
        $source .= $this->renderSingletonProperties($plans, $slots);
        $source .= $this->renderGet($plans, $slots);
        $source .= $this->renderHas($plans);
        $source .= $this->renderSlotMap($slots);
        $source .= $this->renderDefinitionMap($graph, $plans);
        $source .= $this->renderTags($graph, $plans);
        $source .= $this->renderServiceMethods($plans, $slots);

        return rtrim($source) . "\n};\n";
    }

    /**
     * @param ClassPlan $plan
     * @param array<string, int> $slots
     */
    private function classConstruction(array $plan, array $slots): string
    {
        $arguments = [];
        foreach ($plan['arguments'] as $argument) {
            $arguments[] = $argument['kind'] === 'service'
                ? '$this->s' . $slots[$argument['id']] . '()'
                : $argument['code'];
        }

        return 'new \\' . ltrim($plan['class'], '\\') . '(' . implode(', ', $arguments) . ')';
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
     * @param array<string, ServicePlan> $plans
     * @param array<string, int> $slots
     */
    private function renderGet(array $plans, array $slots): string
    {
        $source = "    public function get(string \$id): mixed\n    {\n        return match (\$id) {\n";
        foreach ($plans as $id => $_plan) {
            $source .= '            ' . var_export($id, true) . ' => $this->s' . $slots[$id] . "(),\n";
        }
        $source .= "            default => \$this->fallbackGet(\$id),\n";

        return $source . "        };\n    }\n\n";
    }

    /** @param array<string, ServicePlan> $plans */
    private function renderHas(array $plans): string
    {
        $source = "    public function has(string \$id): bool\n    {\n        return match (\$id) {\n";
        if ($plans !== []) {
            $ids = implode(', ', array_map(static fn(string $id): string => var_export($id, true), array_keys($plans)));
            $source .= "            {$ids} => true,\n";
        }
        $source .= "            default => \$this->fallbackHas(\$id),\n";

        return $source . "        };\n    }\n\n";
    }

    /**
     * @param ClassPlan $plan
     * @param array<string, int> $slots
     */
    private function renderClassMethod(int $slot, array $plan, array $slots): string
    {
        $construction = $this->classConstruction($plan, $slots);
        $source = "    private function s{$slot}(): mixed\n    {\n";
        $source .= $this->renderSeedGuard($slot);

        if ($plan['lifetime'] === LifetimeEnum::Scoped) {
            $source .= "        if (array_key_exists({$slot}, \$this->scope->resolved)) {\n";
            $source .= "            return \$this->scope->resolved[{$slot}];\n";
            $source .= "        }\n\n";
            $source .= "        return \$this->scope->resolved[{$slot}] = {$construction};\n";
        } elseif ($plan['lifetime'] === LifetimeEnum::Singleton) {
            $source .= "        return \$this->v{$slot} ??= {$construction};\n";
        } else {
            $source .= "        return {$construction};\n";
        }

        return $source . "    }\n\n";
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
            $source .= $plan['kind'] === 'value'
                ? $this->renderValueMethod($slots[$id], $plan)
                : $this->renderClassMethod($slots[$id], $plan, $slots);
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
