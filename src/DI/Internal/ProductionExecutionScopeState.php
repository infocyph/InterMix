<?php

declare(strict_types=1);

namespace Infocyph\InterMix\DI\Internal;

/** @internal */
final class ProductionExecutionScopeState
{
    public ?ScopeState $attachedScope = null;

    public ?ScopeState $current = null;
}
