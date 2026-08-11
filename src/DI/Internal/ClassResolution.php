<?php

declare(strict_types=1);

namespace Infocyph\InterMix\DI\Internal;

/** @internal */
final readonly class ClassResolution
{
    public function __construct(
        public object $instance,
        public mixed $returned = null,
        public bool $methodInvoked = false,
    ) {}
}
