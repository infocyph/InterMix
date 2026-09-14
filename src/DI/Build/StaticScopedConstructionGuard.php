<?php

declare(strict_types=1);

namespace Infocyph\InterMix\DI\Build;

use Infocyph\InterMix\DI\Support\LifetimeEnum;
use Infocyph\InterMix\Exceptions\ContainerException;

/**
 * Wrap compiled scoped service entry methods with a shared-construction guard.
 *
 * The wrapper retains the generated seed/cache fast path. The original generated
 * method is renamed and invoked only on a cold miss, so existing renderers keep
 * ownership of class/factory/alias/invocation/hook construction semantics.
 *
 * @internal
 */
final class StaticScopedConstructionGuard
{
    /**
     * @param array<string, array{lifetime: LifetimeEnum}> $plans
     * @param array<string, int> $slots
     */
    public function apply(string $source, array $plans, array $slots): string
    {
        $wrappers = '';
        foreach ($plans as $id => $plan) {
            if ($plan['lifetime'] !== LifetimeEnum::Scoped) {
                continue;
            }

            $slot = $slots[$id];
            $method = "    private function s{$slot}(): mixed\n";
            $coldMethod = "    private function s{$slot}Unguarded(): mixed\n";
            $replacements = 0;
            $source = str_replace($method, $coldMethod, $source, $replacements);
            if ($replacements !== 1) {
                throw new ContainerException(
                    "Unable to install scoped construction guard for compiled service '{$id}'.",
                );
            }

            $exportedId = var_export($id, true);
            $wrappers .= "    private function s{$slot}(): mixed\n"
                . "    {\n"
                . "        \$scope = \$this->contextScopesActive ? \$this->compiledScope() : \$this->scope;\n"
                . "        if (\$scope->hasSeeds && array_key_exists({$slot}, \$scope->seeds)) {\n"
                . "            return \$scope->seeds[{$slot}];\n"
                . "        }\n"
                . "        if (array_key_exists({$slot}, \$scope->resolved)) {\n"
                . "            return \$scope->resolved[{$slot}];\n"
                . "        }\n\n"
                . "        return \$this->constructCompiledScoped(\n"
                . "            \$scope,\n"
                . "            {$slot},\n"
                . "            {$exportedId},\n"
                . "            fn(): mixed => \$this->s{$slot}Unguarded(),\n"
                . "        );\n"
                . "    }\n\n";
        }

        if ($wrappers === '') {
            return $source;
        }

        $end = strrpos($source, "};\n");
        if ($end === false) {
            throw new ContainerException('Unable to finalize compiled scoped construction guards.');
        }

        return substr($source, 0, $end) . $wrappers . substr($source, $end);
    }
}
