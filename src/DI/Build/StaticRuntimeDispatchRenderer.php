<?php

declare(strict_types=1);

namespace Infocyph\InterMix\DI\Build;

use Infocyph\InterMix\DI\RuntimeContainerInterface;
use Infocyph\InterMix\DI\Support\LifetimeEnum;
use Psr\Container\ContainerInterface;

/** @internal */
final class StaticRuntimeDispatchRenderer
{
    /**
     * @param array<string, array{kind: string, lifetime: LifetimeEnum}> $plans
     * @param array<string, int> $slots
     */
    public function renderGet(array $plans, array $slots): string
    {
        $source = "    public function get(string \$id): mixed\n    {\n";
        $source .= "        return match (\$id) {\n";
        foreach ($plans as $rawId => $plan) {
            $id = (string) $rawId;
            if ($this->isRuntimeSelfId($id)) {
                continue;
            }

            $call = '$this->s' . $slots[$id] . '()';
            if ($plan['lifetime'] === LifetimeEnum::Singleton) {
                $call = '$this->resolveCompiledSingleton(fn(): mixed => ' . $call . ')';
            }
            $source .= '            ' . var_export($id, true) . ' => ' . $call . ",\n";
        }
        $source .= "            default => \$this->runtimeSelfOrFallback(\$id),\n";

        return $source . "        };\n    }\n\n";
    }

    /** @param array<string, array{kind: string, lifetime: LifetimeEnum}> $plans */
    public function renderHas(array $plans): string
    {
        $source = "    public function has(string \$id): bool\n    {\n";
        $source .= "        return match (\$id) {\n";

        $ids = [];
        foreach (array_keys($plans) as $rawId) {
            $id = (string) $rawId;
            if (!$this->isRuntimeSelfId($id)) {
                $ids[] = $id;
            }
        }
        if ($ids !== []) {
            $values = implode(', ', array_map(
                static fn(string $id): string => var_export($id, true),
                $ids,
            ));
            $source .= "            {$values} => true,\n";
        }
        $source .= "            default => \$this->runtimeSelfOrFallbackHas(\$id),\n";

        return $source . "        };\n    }\n\n";
    }

    private function isRuntimeSelfId(string $id): bool
    {
        return $id === ContainerInterface::class || $id === RuntimeContainerInterface::class;
    }
}
