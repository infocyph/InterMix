<?php

declare(strict_types=1);

namespace Infocyph\InterMix\DI\Internal;

/** @internal */
final class ExecutionScopeState
{
    public ?LogicalScopeState $attachedScope = null;

    public ?LogicalScopeState $current = null;
}
