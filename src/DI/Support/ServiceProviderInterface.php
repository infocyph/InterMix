<?php

declare(strict_types=1);

namespace Infocyph\InterMix\DI\Support;

use Infocyph\InterMix\DI\ContainerBuilder;

interface ServiceProviderInterface
{
    /**
     * Add declarations to the supplied mutable builder without owning runtime execution.
     */
    public function register(ContainerBuilder $builder): void;
}
