<?php

namespace Infocyph\InterMix\DI\Attribute;

use Closure;
use Infocyph\InterMix\DI\Container;
use Infocyph\InterMix\DI\Support\TraceLevelEnum;

final class DeferredInitializer
{
    private bool $done = false;
    private mixed $value = null;

    /**
     * Initializes a new instance of the DeferredInitializer class.
     *
     * @param Closure $factory A closure that will be invoked to initialize the lazy-loaded instance.
     * @param Container $container The container to which this instance is bound.
     */
    public function __construct(private readonly Closure $factory, private readonly Container $container)
    {
    }

    /**
     * Calls the underlying factory closure to initialize the lazy-loaded instance.
     *
     * @return mixed The instance created by the factory.
     */
    public function __invoke(): mixed
    {
        if ($this->done) {
            return $this->value;
        }
        $this->container->tracer()->push('lazy-init', TraceLevelEnum::Verbose);
        $this->value = ($this->factory)();
        $this->done  = true;
        return $this->value;
    }
}
