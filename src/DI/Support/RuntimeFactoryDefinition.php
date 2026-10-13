<?php

declare(strict_types=1);

namespace Infocyph\InterMix\DI\Support;

use Closure;
use Infocyph\InterMix\DI\RuntimeContainerInterface;

/** @internal */
final readonly class RuntimeFactoryDefinition
{
    /**
     * Declare an opaque closure whose execution remains protected by runtime fallback.
     */
    public function __construct(private Closure $factory) {}

    /**
     * Execute the closure with the runtime supplied by the caller.
     */
    public function resolve(RuntimeContainerInterface $container): mixed
    {
        return ($this->factory)($container);
    }
}
