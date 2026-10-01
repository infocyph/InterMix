<?php

declare(strict_types=1);

namespace Infocyph\InterMix\DI\Build;

use Infocyph\InterMix\DI\Support\LifetimeEnum;

/** @internal */
final class StaticScopeAccessRenderer
{
    public function constructionGuard(LifetimeEnum $lifetime, ?string $id = null): string
    {
        if ($lifetime !== LifetimeEnum::Scoped) {
            return '';
        }

        $message = var_export(
            'Scoped entry \'' . ($id ?? '') . '\' requires an active scope.',
            true,
        );

        return "        if (\$scope->name === 'root') {\n"
            . "            throw new \\Infocyph\\InterMix\\Exceptions\\ContainerException({$message});\n"
            . "        }\n\n";
    }

    /** @param array<string, array{kind: string, lifetime: LifetimeEnum}> $plans */
    public function requiresCaptiveGuard(array $plans): bool
    {
        foreach ($plans as $plan) {
            if ($plan['lifetime'] === LifetimeEnum::Singleton && $plan['kind'] !== 'value') {
                return true;
            }
        }

        return false;
    }

    public function seedGuard(
        int $slot,
        LifetimeEnum $lifetime,
        ?string $id = null,
        bool $guardCaptive = true,
    ): string {
        if ($lifetime === LifetimeEnum::Scoped) {
            $source = "        \$scope = \$this->contextScopesActive ? \$this->compiledScope() : \$this->scope;\n";
            if ($guardCaptive) {
                $serviceId = var_export($id ?? '', true);
                $source .= "        if (\$this->compiledSingletonResolutionActive) {\n"
                    . "            \$this->assertCompiledScopedResolution({$serviceId});\n"
                    . "        }\n";
            }

            return $source
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
