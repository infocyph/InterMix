<?php

declare(strict_types=1);

namespace Infocyph\InterMix\Tests\StaticAnalysis;

use Infocyph\InterMix\Fence\Limit;
use Infocyph\InterMix\Fence\Multi;
use Infocyph\InterMix\Fence\Single;
use Infocyph\InterMix\Remix\ConditionableTappable;
use Infocyph\InterMix\Remix\MacroMix;

/**
 * Supplies concrete consumers so static analysis can inspect traits in class context.
 */
final class TraitUsageFixture
{
    /**
     * @return list<object>
     */
    public static function consumers(): array
    {
        return [
            new class {
                use Limit;
            },
            new class {
                use Multi;
            },
            new class {
                use Single;
            },
            new class {
                use ConditionableTappable;
                use MacroMix;
            },
        ];
    }
}
