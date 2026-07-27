<?php

declare(strict_types=1);

namespace Infocyph\InterMix\DI\Support;

use Closure;
use Infocyph\InterMix\DI\Container;

/**
 * A container factory whose arguments are explicit and therefore require no
 * reflection-based parameter resolution.
 */
final readonly class DirectFactory
{
    public function __construct(
        private Closure $factory,
        private Container $container,
    ) {}

    public function resolve(): mixed
    {
        return ($this->factory)($this->container);
    }
}
