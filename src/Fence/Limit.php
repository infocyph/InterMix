<?php

// src/Fence/Limit.php
declare(strict_types=1);

namespace Infocyph\InterMix\Fence;

// Public trait consumers live in downstream projects and the excluded test suite.
// @phpstan-ignore trait.unused
trait Limit
{
    use Fence;

    /**
     * Multiton: keyed instances allowed.
     */
    public const FENCE_KEYED = true;

    /**
     * Default limit (can be overridden via setLimit()).
     */
    public const FENCE_LIMIT = 2;
}
