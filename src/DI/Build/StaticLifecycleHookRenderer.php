<?php

declare(strict_types=1);

namespace Infocyph\InterMix\DI\Build;

use Infocyph\InterMix\DI\Support\LifetimeEnum;

/**
 * @phpstan-type ServicePlan array{kind: string, lifetime: LifetimeEnum}
 * @internal
 */
final class StaticLifecycleHookRenderer
{
    public function hasResolutionHooks(DefinitionGraph $graph, string $id): bool
    {
        return $graph->hasResolvingHook($id) || $graph->hasResolvedHook($id);
    }

    public function renderClassMethod(
        DefinitionGraph $graph,
        int $slot,
        string $id,
        LifetimeEnum $lifetime,
        string $serviceStatements,
    ): string {
        $source = "    private function s{$slot}(): mixed\n    {\n";
        $source .= $this->seedGuard($slot);
        if ($lifetime === LifetimeEnum::Scoped) {
            $source .= "        if (isset(\$this->scope->resolved[{$slot}])) {\n";
            $source .= "            return \$this->scope->resolved[{$slot}];\n";
            $source .= "        }\n\n";
        } elseif ($lifetime === LifetimeEnum::Singleton) {
            $source .= "        if (\$this->v{$slot} !== null) {\n";
            $source .= "            return \$this->v{$slot};\n";
            $source .= "        }\n\n";
        }
        if ($graph->hasResolvingHook($id)) {
            $source .= '        $this->dispatchCompiledResolvingHooks(' . var_export($id, true) . ");\n\n";
        }
        $source .= $serviceStatements;
        if ($lifetime === LifetimeEnum::Scoped) {
            $source .= "\n        \$this->scope->resolved[{$slot}] = \$instance;\n";
        } elseif ($lifetime === LifetimeEnum::Singleton) {
            $source .= "\n        \$this->v{$slot} = \$instance;\n";
        }
        if ($graph->hasResolvedHook($id)) {
            $source .= '        $this->dispatchCompiledResolvedHooks(' . var_export($id, true) . ", \$instance);\n";
        }

        return $source . "\n        return \$instance;\n    }\n\n";
    }

    public function renderExpressionMethod(
        DefinitionGraph $graph,
        int $slot,
        string $id,
        LifetimeEnum $lifetime,
        string $expression,
        string $singletonStore,
    ): string {
        $source = "    private function s{$slot}(): mixed\n    {\n";
        $source .= $this->seedGuard($slot);
        if ($lifetime === LifetimeEnum::Scoped) {
            $source .= "        if (array_key_exists({$slot}, \$this->scope->resolved)) {\n";
            $source .= "            return \$this->scope->resolved[{$slot}];\n";
            $source .= "        }\n\n";
        } elseif ($lifetime === LifetimeEnum::Singleton) {
            $source .= "        if (array_key_exists({$slot}, \$this->{$singletonStore})) {\n";
            $source .= "            return \$this->{$singletonStore}[{$slot}];\n";
            $source .= "        }\n\n";
        }
        if ($graph->hasResolvingHook($id)) {
            $source .= '        $this->dispatchCompiledResolvingHooks(' . var_export($id, true) . ");\n\n";
        }
        $source .= "        \$value = {$expression};\n";
        if ($lifetime === LifetimeEnum::Scoped) {
            $source .= "        \$this->scope->resolved[{$slot}] = \$value;\n";
        } elseif ($lifetime === LifetimeEnum::Singleton) {
            $source .= "        \$this->{$singletonStore}[{$slot}] = \$value;\n";
        }
        if ($graph->hasResolvedHook($id)) {
            $source .= '        $this->dispatchCompiledResolvedHooks(' . var_export($id, true) . ", \$value);\n";
        }

        return $source . "\n        return \$value;\n    }\n\n";
    }

    public function renderScopeLeaveHooks(DefinitionGraph $graph): string
    {
        $scopes = $graph->scopeLeaveHookScopes();
        if ($scopes === []) {
            return '';
        }
        sort($scopes, SORT_STRING);
        $values = implode(', ', array_map(static fn(string $scope): string => var_export($scope, true), $scopes));

        return "    protected function requiresScopeLeaveHook(string \$scope): bool\n"
            . "    {\n"
            . "        return match (\$scope) {\n"
            . "            {$values} => true,\n"
            . "            default => false,\n"
            . "        };\n"
            . "    }\n\n";
    }

    /** @param array<string, ServicePlan> $plans */
    public function renderValueSingletonProperties(DefinitionGraph $graph, array $plans): string
    {
        foreach ($plans as $id => $plan) {
            if ($plan['kind'] === 'value'
                && $plan['lifetime'] === LifetimeEnum::Singleton
                && $this->hasResolutionHooks($graph, $id)
            ) {
                return "    /** @var array<int, mixed> */\n    private array \$hookedValueSingletons = [];\n\n";
            }
        }

        return '';
    }

    private function seedGuard(int $slot): string
    {
        return "        if (\$this->scope->parent !== null && array_key_exists({$slot}, \$this->scope->seeds)) {\n"
            . "            return \$this->scope->seeds[{$slot}];\n"
            . "        }\n\n";
    }
}
