<?php

declare(strict_types=1);

namespace Infocyph\InterMix\Tests\StaticAnalysis;

use Infocyph\InterMix\Fence\Fence;
use Infocyph\InterMix\Fence\Limit;
use Infocyph\InterMix\Fence\Multi;
use Infocyph\InterMix\Fence\Single;
use Infocyph\InterMix\Remix\ConditionableTappable;
use Infocyph\InterMix\Remix\MacroMix;

final class FenceConsumer
{
    use Fence;
}

final class LimitConsumer
{
    use Limit;
}

final class MultiConsumer
{
    use Multi;
}

final class SingleConsumer
{
    use Single;
}

final class ConditionableTappableConsumer
{
    use ConditionableTappable;
}

final class MacroMixConsumer
{
    use MacroMix;
}
