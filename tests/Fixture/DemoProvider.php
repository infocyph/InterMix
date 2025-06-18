<?php

namespace Infocyph\InterMix\Tests\Fixture;

use Infocyph\InterMix\DI\Container;
use Infocyph\InterMix\DI\Support\ServiceProviderInterface;

final class DemoProvider implements ServiceProviderInterface
{
    public function register(Container $container): void
    {
        $container->definitions()->bind(FooService::class, fn () => new FooService());
    }
}
