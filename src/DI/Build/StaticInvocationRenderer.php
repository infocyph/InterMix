<?php

declare(strict_types=1);

namespace Infocyph\InterMix\DI\Build;

use Infocyph\InterMix\DI\Support\LifetimeEnum;

/**
 * @phpstan-type ServiceArgument array{kind: 'service', id: string}|array{kind: 'value', code: string}
 * @phpstan-type PropertyPlan array{declaring: class-string, property: string, static: bool, argument: ServiceArgument|null, runtime?: 'attribute'|'assign'}
 * @phpstan-type MethodPlan array{method: string, arguments: list<ServiceArgument>, dependencies: list<string>, runtime?: bool}
 * @phpstan-type InvocationPlan array{kind: 'invocation', class: class-string, lifetime: LifetimeEnum, arguments: list<ServiceArgument>, properties: list<PropertyPlan>, invocation: MethodPlan, dependencies: list<string>}
 * @internal
 */
final class StaticInvocationRenderer
{
    /**
     * @param InvocationPlan $plan
     * @param array<string, int> $slots
     */
    public function renderMethod(
        int $slot,
        array $plan,
        array $slots,
        string $id,
        bool $resolvingHook = false,
        bool $resolvedHook = false,
    ): string {
        if (!$resolvingHook && !$resolvedHook) {
            return $this->renderPlainMethod($slot, $plan, $slots);
        }

        $source = "    private function s{$slot}(): mixed\n    {\n";
        $source .= $this->seedGuard($slot);
        $source .= $this->cacheGuard($slot, $plan['lifetime']);
        if ($resolvingHook) {
            $source .= '        $this->dispatchCompiledResolvingHooks(' . var_export($id, true) . ");\n\n";
        }
        $source .= $this->statements($plan, $slots);
        $source .= $this->cacheResult($slot, $plan['lifetime']);
        if ($resolvedHook) {
            $source .= '        $this->dispatchCompiledResolvedHooks(' . var_export($id, true) . ", \$result);\n";
        }
        $source .= "\n        return \$result;\n";

        return $source . "    }\n\n";
    }

    /** @param array<string, array{kind: string, lifetime: LifetimeEnum}> $plans */
    public function renderSingletonProperties(array $plans): string
    {
        foreach ($plans as $plan) {
            if ($plan['kind'] === 'invocation' && $plan['lifetime'] === LifetimeEnum::Singleton) {
                return "    /** @var array<int, mixed> */\n    private array \$invocationSingletons = [];\n\n";
            }
        }

        return '';
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

    private function cacheGuard(int $slot, LifetimeEnum $lifetime): string
    {
        if ($lifetime === LifetimeEnum::Scoped) {
            return "        if (array_key_exists({$slot}, \$this->scope->resolved)) {\n"
                . "            return \$this->scope->resolved[{$slot}];\n"
                . "        }\n\n";
        }
        if ($lifetime === LifetimeEnum::Singleton) {
            return "        if (array_key_exists({$slot}, \$this->invocationSingletons)) {\n"
                . "            return \$this->invocationSingletons[{$slot}];\n"
                . "        }\n\n";
        }

        return '';
    }

    private function cacheResult(int $slot, LifetimeEnum $lifetime): string
    {
        if ($lifetime === LifetimeEnum::Scoped) {
            return "\n        \$this->scope->resolved[{$slot}] = \$result;\n";
        }
        if ($lifetime === LifetimeEnum::Singleton) {
            return "\n        \$this->invocationSingletons[{$slot}] = \$result;\n";
        }

        return '';
    }

    /**
     * @param InvocationPlan $plan
     * @param array<string, int> $slots
     */
    private function renderPlainMethod(int $slot, array $plan, array $slots): string
    {
        $source = "    private function s{$slot}(): mixed\n    {\n";
        $source .= $this->seedGuard($slot);

        if ($plan['lifetime'] === LifetimeEnum::Scoped) {
            $source .= "        if (array_key_exists({$slot}, \$this->scope->resolved)) {\n";
            $source .= "            return \$this->scope->resolved[{$slot}];\n";
            $source .= "        }\n\n";
            $source .= $this->statements($plan, $slots);
            $source .= "\n        return \$this->scope->resolved[{$slot}] = \$result;\n";
        } elseif ($plan['lifetime'] === LifetimeEnum::Singleton) {
            $source .= "        if (array_key_exists({$slot}, \$this->invocationSingletons)) {\n";
            $source .= "            return \$this->invocationSingletons[{$slot}];\n";
            $source .= "        }\n\n";
            $source .= $this->statements($plan, $slots);
            $source .= "\n        return \$this->invocationSingletons[{$slot}] = \$result;\n";
        } else {
            $source .= $this->statements($plan, $slots);
            $source .= "\n        return \$result;\n";
        }

        return $source . "    }\n\n";
    }

    private function seedGuard(int $slot): string
    {
        return "        if (\$this->scope->parent !== null && array_key_exists({$slot}, \$this->scope->seeds)) {\n"
            . "            return \$this->scope->seeds[{$slot}];\n"
            . "        }\n\n";
    }

    /**
     * @param InvocationPlan $plan
     * @param array<string, int> $slots
     */
    private function statements(array $plan, array $slots): string
    {
        $constructorArguments = [];
        foreach ($plan['arguments'] as $argument) {
            $constructorArguments[] = $this->argumentExpression($argument, $slots);
        }

        $class = '\\' . ltrim($plan['class'], '\\');
        $source = '        $instance = new ' . $class . '(' . implode(', ', $constructorArguments) . ");\n";
        $islands = new StaticRuntimeIslandRenderer();
        $source .= $islands->propertyStatements($plan['properties'], $slots);
        $method = $islands->methodExpression($plan['class'], $plan['invocation'], $slots);

        return $source . "        \$result = {$method};\n";
    }
}
