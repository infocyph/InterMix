<?php

use AbmmHasan\InterMix\Tests\Fixture\InjectionOnlyClass;

use function AbmmHasan\InterMix\container;

test('Instance', function () {
    expect(container(InjectionOnlyClass::class, 'injection1'))
        ->toBeInstanceOf(InjectionOnlyClass::class)
        ->and(container(null, 'injection2')->get(InjectionOnlyClass::class))
        ->toBeInstanceOf(InjectionOnlyClass::class);
});