<?php

declare(strict_types=1);

namespace Infocyph\InterMix\DI\Support;

use Closure;
use Infocyph\InterMix\DI\RuntimeContainerInterface;

/** @internal */
final readonly class RuntimeFactoryDefinition
{
    public function __construct(private Closure $factory) {}

    public function resolve(RuntimeContainerInterface $container): mixed
    {
        return ($this->factory)($container);
    }
}
