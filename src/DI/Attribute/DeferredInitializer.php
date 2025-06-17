<?php

namespace Infocyph\InterMix\DI\Attribute;

use Closure;
use Infocyph\InterMix\DI\Container;
use Infocyph\InterMix\DI\Support\TraceLevel;

readonly class DeferredInitializer
{
    public function __construct(private Closure $factory, private Container $container)
    {
    }

    public function __invoke(): mixed
    {
        $this->container->tracer()->push('lazy-init', TraceLevel::Verbose);
        $val = ($this->factory)();
        $this->container->tracer()->pop();
        return $val;
    }
}
