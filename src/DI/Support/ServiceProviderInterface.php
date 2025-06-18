<?php

namespace Infocyph\InterMix\DI\Support;

use Infocyph\InterMix\DI\Container;

interface ServiceProviderInterface
{
    /**
     * Register the service provider.
     *
     * @param Container $container The container to register the provider with.
     */
    public function register(Container $container): void;
}
