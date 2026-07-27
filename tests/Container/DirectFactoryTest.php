<?php

declare(strict_types=1);

use Infocyph\InterMix\DI\Container;
use Infocyph\InterMix\DI\Support\LifetimeEnum;

it('resolves direct factories consistently in every DI mode and lifetime', function (
    bool $injection,
    string $api,
    LifetimeEnum $lifetime,
) {
    $container = Container::instance(uniqid('direct_factory_', true));
    $container->options()->setOptions(injection: $injection)->end();
    $calls = 0;

    $factory = static function (Container $resolvedContainer) use (&$calls, $container): object {
        expect($resolvedContainer)->toBe($container);
        ++$calls;

        return new stdClass();
    };

    if ($api === 'bindFactory') {
        $container->bindFactory('direct.service', $factory, $lifetime);
    } else {
        $pending = $container->factory('direct.service', $factory);
        match ($lifetime) {
            LifetimeEnum::Singleton => $pending->singleton(),
            LifetimeEnum::Scoped => $pending->scoped(),
            LifetimeEnum::Transient => $pending->transient(),
        };
    }

    if ($lifetime === LifetimeEnum::Scoped) {
        $container->enterScope('request-a');
    }

    $first = $container->get('direct.service');
    $second = $container->get('direct.service');

    if ($lifetime === LifetimeEnum::Scoped) {
        $container->leaveScope();
        $container->enterScope('request-b');
        $third = $container->get('direct.service');
        $container->leaveScope();

        expect($first)->toBe($second)
            ->and($third)->not->toBe($first)
            ->and($calls)->toBe(2);
    } elseif ($lifetime === LifetimeEnum::Singleton) {
        expect($first)->toBe($second)
            ->and($calls)->toBe(1);
    } else {
        expect($first)->not->toBe($second)
            ->and($calls)->toBe(2);
    }

    $container->unset();
})->with(function (): iterable {
    foreach ([true, false] as $injection) {
        foreach (['bindFactory', 'factory'] as $api) {
            foreach (LifetimeEnum::cases() as $lifetime) {
                yield sprintf(
                    'injection %s, %s, %s',
                    $injection ? 'on' : 'off',
                    $api,
                    $lifetime->name,
                ) => [$injection, $api, $lifetime];
            }
        }
    }
});

it('resolves tagged direct factories without autowiring their closures', function () {
    $container = Container::instance(uniqid('tagged_direct_factory_', true));

    $container->bindFactory(
        'direct.tagged',
        static function (Container $resolvedContainer): object {
            expect($resolvedContainer)->toBeInstanceOf(Container::class);

            return new stdClass();
        },
        tags: ['direct'],
    );

    expect($container->findByTag('direct'))
        ->toHaveKey('direct.tagged')
        ->and($container->get('direct.tagged'))
        ->toBeInstanceOf(stdClass::class);

    $container->unset();
});

it('keeps the pending factory API reflection free', function () {
    $container = Container::instance(uniqid('pending_direct_factory_', true));
    $calls = 0;

    $container->factory(
        'direct.transient',
        static function (Container $resolvedContainer) use (&$calls, $container): object {
            expect($resolvedContainer)->toBe($container);
            ++$calls;

            return new stdClass();
        },
    )->transient();

    expect($container->get('direct.transient'))->not->toBe($container->get('direct.transient'))
        ->and($calls)->toBe(2);

    $container->unset();
});
