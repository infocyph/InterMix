<?php

declare(strict_types=1);

namespace Infocyph\InterMix\DI\Support;

/** @internal */
final readonly class ValueDefinition
{
    public function __construct(public mixed $value) {}
}
