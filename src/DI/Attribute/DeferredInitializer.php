<?php

namespace Infocyph\InterMix\DI\Attribute;

use Closure;
use Infocyph\InterMix\DI\Container;
use Infocyph\InterMix\DI\Support\TraceLevel;

readonly class DeferredInitializer
{
    /**
     * Initializes a new instance of the DeferredInitializer class.
     *
     * @param Closure $factory A closure that will be invoked to initialize the lazy-loaded instance.
     * @param Container $container The container to which this instance is bound.
     */
    public function __construct(private Closure $factory, private Container $container)
    {
    }

    /**
     * Calls the underlying factory closure to initialize the lazy-loaded instance.
     *
     * @return mixed The instance created by the factory.
     */
    public function __invoke(): mixed
    {
        $this->container->tracer()->push('lazy-init', TraceLevel::Verbose);
        return ($this->factory)();
    }
}
