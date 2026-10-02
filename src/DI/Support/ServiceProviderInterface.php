<?php

declare(strict_types=1);

namespace Infocyph\InterMix\DI\Support;

use Infocyph\InterMix\DI\ContainerBuilder;

interface ServiceProviderInterface
{
    public function register(ContainerBuilder $builder): void;
}
