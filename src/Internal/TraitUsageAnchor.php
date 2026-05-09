<?php

declare(strict_types=1);

namespace Infocyph\InterMix\Internal;

use Infocyph\InterMix\Fence\Limit;
use Infocyph\InterMix\Fence\Multi;
use Infocyph\InterMix\Fence\Single;
use Infocyph\InterMix\Remix\ConditionableTappable;
use Infocyph\InterMix\Remix\MacroMix;

final class LimitUsageAnchor
{
    use Limit;
}

final class MultiUsageAnchor
{
    use Multi;
}

final class SingleUsageAnchor
{
    use Single;
}

final class ConditionableUsageAnchor
{
    use ConditionableTappable;
}

final class MacroUsageAnchor
{
    use MacroMix;
}
