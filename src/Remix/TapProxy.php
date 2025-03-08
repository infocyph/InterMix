<?php

namespace Infocyph\InterMix\Remix;

class TapProxy
{
    /**
     * Create a new TapProxy instance.
     *
     * @param  mixed  $target
     * @return void
     */
    public function __construct(public mixed $target)
    {
    }

    /**
     * Proxy a method call to the target, then return the target.
     *
     * @param string $method
     * @param array $parameters
     * @return mixed
     */
    public function __call(string $method, array $parameters)
    {
        $this->target->{$method}(...$parameters);
        return $this->target;
    }
}
