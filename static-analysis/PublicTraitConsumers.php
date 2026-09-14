<?php

declare(strict_types=1);

use Infocyph\InterMix\Fence\Fence;
use Infocyph\InterMix\Fence\Limit;
use Infocyph\InterMix\Fence\Multi;
use Infocyph\InterMix\Fence\Single;
use Infocyph\InterMix\Remix\ConditionableTappable;
use Infocyph\InterMix\Remix\MacroMix;

return [
    new class {
        use Fence;
    },
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
    },
    new class {
        use MacroMix;
    },
];
