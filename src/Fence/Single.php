<?php

// src/Fence/Single.php
declare(strict_types=1);

namespace Infocyph\InterMix\Fence;

// Public trait consumers live in downstream projects and the excluded test suite.
// @phpstan-ignore trait.unused
trait Single
{
    use Fence;

    /**
     * Always singleton (ignore key).
     */
    public const FENCE_KEYED = false;

    /**
     * Only one instance allowed.
     */
    public const FENCE_LIMIT = 1;
}
