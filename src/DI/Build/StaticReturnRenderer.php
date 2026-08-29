<?php

declare(strict_types=1);

namespace Infocyph\InterMix\DI\Build;

use Infocyph\InterMix\DI\Support\LifetimeEnum;

/**
 * @phpstan-type ServiceArgument array{kind: 'service', id: string}|array{kind: 'value', code: string}
 * @phpstan-type PropertyPlan array{declaring: class-string, property: string, static: bool, argument: ServiceArgument|null, runtime?: 'attribute'|'assign'}
 * @phpstan-type MethodPlan array{method: string, arguments: list<ServiceArgument>, dependencies: list<string>, runtime?: bool}
 * @phpstan-type ClassPlan array{kind: 'class', class: class-string, lifetime: LifetimeEnum, arguments: list<ServiceArgument>, properties: list<PropertyPlan>, postMethod: MethodPlan|null, dependencies: list<string>}
 * @phpstan-type ServicePlan array{kind: string, lifetime: LifetimeEnum, postMethod?: MethodPlan|null}
 * @internal
 */
final class StaticReturnRenderer
{
    /**
     * @param ClassPlan $plan
     * @param MethodPlan $method
     * @param array<string, int> $slots
     */
    public function invocationStatement(array $plan, array $method, array $slots, int $slot): string
    {
        $expression = new StaticRuntimeIslandRenderer()->methodExpression(
            $plan['class'],
            $method,
            $slots,
        );
        $target = $plan['lifetime'] === LifetimeEnum::Scoped
            ? "\$this->scope->returned[{$slot}]"
            : "\$this->classReturns[{$slot}]";

        return "\n        {$target} = {$expression};\n";
    }

    /**
     * @param array<string, ClassPlan|ServicePlan> $plans
     * @param array<string, int> $slots
     */
    public function renderDispatch(array $plans, array $slots): string
    {
        $entries = [];
        foreach ($plans as $id => $plan) {
            if ($plan['kind'] !== 'class' || !is_array($plan['postMethod'] ?? null)) {
                continue;
            }
            $entries[] = [
                'id' => $id,
                'slot' => $slots[$id],
                'scoped' => $plan['lifetime'] === LifetimeEnum::Scoped,
            ];
        }
        if ($entries === []) {
            return '';
        }

        $source = "    protected function compiledReturn(string \$id, mixed &\$returned): bool\n    {\n";
        foreach ($entries as $entry) {
            $id = var_export($entry['id'], true);
            $store = $entry['scoped'] ? '$this->scope->returned' : '$this->classReturns';
            $slot = $entry['slot'];
            $source .= "        if (\$id === {$id} && array_key_exists({$slot}, {$store})) {\n";
            $source .= "            \$returned = {$store}[{$slot}];\n\n";
            $source .= "            return true;\n";
            $source .= "        }\n";
        }

        return $source . "\n        return false;\n    }\n\n";
    }

    /** @param array<string, ClassPlan|ServicePlan> $plans */
    public function renderProperties(array $plans): string
    {
        foreach ($plans as $plan) {
            if ($plan['kind'] === 'class'
                && is_array($plan['postMethod'] ?? null)
                && $plan['lifetime'] !== LifetimeEnum::Scoped
            ) {
                return "    /** @var array<int, mixed> */\n    private array \$classReturns = [];\n\n";
            }
        }

        return '';
    }
}
