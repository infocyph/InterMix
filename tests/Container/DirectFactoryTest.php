<?php

declare(strict_types=1);

use Infocyph\InterMix\DI\ContainerBuilder;
use Infocyph\InterMix\DI\RuntimeContainerInterface;
use Infocyph\InterMix\DI\Support\LifetimeEnum;

test('explicit runtime factories obey all three lifetimes', function (LifetimeEnum $lifetime) {
    $calls = 0;
    $runtime = ContainerBuilder::create()
        ->factory(
            'factory.service',
            static function (RuntimeContainerInterface $received) use (&$calls): object {
                expect($received)->toBeInstanceOf(RuntimeContainerInterface::class);
                ++$calls;

                return new stdClass();
            },
            $lifetime,
        )
        ->build();

    if ($lifetime === LifetimeEnum::Scoped) {
        $firstPair = $runtime->withinScope('a', static fn(RuntimeContainerInterface $active): array => [
            $active->get('factory.service'),
            $active->get('factory.service'),
        ]);
        $third = $runtime->withinScope(
            'b',
            static fn(RuntimeContainerInterface $active): object => $active->get('factory.service'),
        );

        expect($firstPair[0])->toBe($firstPair[1])
            ->and($third)->not->toBe($firstPair[0])
            ->and($calls)->toBe(2);

        return;
    }

    $first = $runtime->get('factory.service');
    $second = $runtime->get('factory.service');
    if ($lifetime === LifetimeEnum::Singleton) {
        expect($first)->toBe($second)->and($calls)->toBe(1);
    } else {
        expect($first)->not->toBe($second)->and($calls)->toBe(2);
    }
})->with(LifetimeEnum::cases());

test('tagged factories resolve lazily as values', function () {
    $calls = 0;
    $runtime = ContainerBuilder::create()
        ->factory(
            'factory.tagged',
            static function () use (&$calls): object {
                ++$calls;

                return new stdClass();
            },
            tags: ['workers'],
        )
        ->build();

    $iterator = $runtime->tagged('workers');
    expect($calls)->toBe(0);

    $values = iterator_to_array($iterator);
    expect($values)->toHaveKey('factory.tagged')
        ->and($values['factory.tagged'])->toBeInstanceOf(stdClass::class)
        ->and($calls)->toBe(1);
});
