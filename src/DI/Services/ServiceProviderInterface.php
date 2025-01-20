<?php

declare(strict_types=1);

namespace Infocyph\InterMix\DI\Services;

use Infocyph\InterMix\DI\Container;

/**
 * A service provider registers multiple services/definitions in one place.
 */
interface ServiceProviderInterface
{
    /**
     * Register definitions/services to the container.
     */
    public function register(Container $container): void;
}
