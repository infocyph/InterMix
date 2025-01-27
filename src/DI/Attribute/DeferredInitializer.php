<?php

namespace Infocyph\InterMix\DI\Attribute;

use Closure;

readonly class DeferredInitializer
{
    public function __construct(private Closure $factory)
    {
    }

    public function __invoke(): mixed
    {
        return ($this->factory)();
    }
}
