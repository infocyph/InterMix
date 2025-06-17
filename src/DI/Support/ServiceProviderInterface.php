<?php

namespace Infocyph\InterMix\DI\Support;

use Infocyph\InterMix\DI\Container;

interface ServiceProviderInterface
{
    public function register(Container $container): void;
}
