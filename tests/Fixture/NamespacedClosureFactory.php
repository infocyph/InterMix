<?php

declare(strict_types=1);

namespace Infocyph\InterMix\Tests\Fixture;

use Infocyph\InterMix\DI\Container;
use stdClass;

final class NamespacedClosureFactory
{
    /**
     * @return array{0:string,1:stdClass}
     */
    public static function invoke(Container $container): array
    {
        $withoutArguments = $container->call(static fn(): string => 'ok');
        $withArgument = $container->call(static fn(stdClass $value): stdClass => $value);

        return [$withoutArguments, $withArgument];
    }
}
