<?php

declare(strict_types=1);

namespace Infocyph\InterMix\Tests\StaticAnalysis;

use Infocyph\InterMix\Fence\Fence;
use Infocyph\InterMix\Fence\Limit;
use Infocyph\InterMix\Fence\Multi;
use Infocyph\InterMix\Fence\Single;
use Infocyph\InterMix\Remix\ConditionableTappable;
use Infocyph\InterMix\Remix\MacroMix;

final class PublicTraitConsumers
{
    public static function fence(): object
    {
        return new class {
            use Fence;
        };
    }

    public static function limit(): object
    {
        return new class {
            use Limit;
        };
    }

    public static function multi(): object
    {
        return new class {
            use Multi;
        };
    }

    public static function single(): object
    {
        return new class {
            use Single;
        };
    }

    public static function conditionableTappable(): object
    {
        return new class {
            use ConditionableTappable;
        };
    }

    public static function macroMix(): object
    {
        return new class {
            use MacroMix;
        };
    }
}
