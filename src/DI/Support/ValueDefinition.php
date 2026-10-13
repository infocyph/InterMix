<?php

declare(strict_types=1);

namespace Infocyph\InterMix\DI\Support;

/** @internal */
final readonly class ValueDefinition
{
    /**
     * Wrap a literal so definition resolution never interprets it as executable metadata.
     */
    public function __construct(public mixed $value) {}
}
