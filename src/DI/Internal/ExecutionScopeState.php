<?php

declare(strict_types=1);

namespace Infocyph\InterMix\DI\Internal;

/** @internal */
final class ExecutionScopeState
{
    public string $currentScope = 'root';

    /** @var array<string, array<string, mixed>> */
    public array $resolvedScoped = [];

    /** @var array<string, array<string, mixed>> */
    public array $scopeSeeds = [];

    /** @var array<int, string> */
    public array $scopeStack = [];
}
