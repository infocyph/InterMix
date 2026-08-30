<?php

declare(strict_types=1);

namespace Infocyph\InterMix\DI\Build;

use Infocyph\InterMix\DI\Support\LifetimeEnum;

/** @internal */
final class StaticScopeAccessRenderer
{
    public function seedGuard(int $slot, LifetimeEnum $lifetime): string
    {
        if ($lifetime === LifetimeEnum::Scoped) {
            return "        \$scope = \$this->contextScopesActive ? \$this->compiledScope() : \$this->scope;\n"
                . "        if (\$scope->hasSeeds && array_key_exists({$slot}, \$scope->seeds)) {\n"
                . "            return \$scope->seeds[{$slot}];\n"
                . "        }\n\n";
        }

        return "        if (\$this->contextScopesActive) {\n"
            . "            \$scope = \$this->compiledScope();\n"
            . "            if (\$scope->hasSeeds && array_key_exists({$slot}, \$scope->seeds)) {\n"
            . "                return \$scope->seeds[{$slot}];\n"
            . "            }\n"
            . "        } elseif (\$this->scope->hasSeeds && array_key_exists({$slot}, \$this->scope->seeds)) {\n"
            . "            return \$this->scope->seeds[{$slot}];\n"
            . "        }\n\n";
    }
}
