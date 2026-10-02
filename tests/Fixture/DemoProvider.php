<?php

declare(strict_types=1);

namespace Infocyph\InterMix\Tests\Fixture;

use Infocyph\InterMix\DI\ContainerBuilder;
use Infocyph\InterMix\DI\Support\ServiceProviderInterface;

final class DemoProvider implements ServiceProviderInterface
{
    public function register(ContainerBuilder $builder): void
    {
        $builder->factory(FooService::class, static fn() => new FooService());
    }
}
