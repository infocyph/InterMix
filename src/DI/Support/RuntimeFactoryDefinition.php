<?php

declare(strict_types=1);

namespace Infocyph\InterMix\DI\Support;

use Closure;
use Infocyph\InterMix\DI\Container;

/** @internal */
final readonly class RuntimeFactoryDefinition
{
    public function __construct(private Closure $factory) {}

    public function resolve(Container $container): mixed
    {
        return ($this->factory)($container);
    }
}
