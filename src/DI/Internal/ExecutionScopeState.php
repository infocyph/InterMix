<?php

declare(strict_types=1);

namespace Infocyph\InterMix\DI\Internal;

/** @internal */
final class ExecutionScopeState
{
    public ?LogicalScopeState $attachedScope = null;

    public ?LogicalScopeState $current = null;

    public string $fastCurrentScope = 'root';

    /** @var array<string, array<string, mixed>> */
    public array $fastResolvedScoped = [];

    /** @var array<string, array<string, mixed>> */
    public array $fastScopeSeeds = [];

    /** @var array<int, string> */
    public array $fastScopeStack = [];
}
