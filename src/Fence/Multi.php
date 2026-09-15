<?php

// src/Fence/Multi.php
declare(strict_types=1);

namespace Infocyph\InterMix\Fence;

trait Multi
{
    use Fence;

    /**
     * Multiton: keyed instances allowed.
     */
    public const FENCE_KEYED = true;

    /**
     * Unlimited instances.
     */
    public const FENCE_LIMIT = PHP_INT_MAX;
}
