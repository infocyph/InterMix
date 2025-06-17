<?php

namespace Infocyph\InterMix\DI\Reflection;

use Infocyph\InterMix\DI\Container;

interface ServiceProviderInterface
{
    public function register(Container $container): void;
}
