<?php

// src/Fence/Single.php
declare(strict_types=1);

namespace Infocyph\InterMix\Fence;

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
