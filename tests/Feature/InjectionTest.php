<?php

use AbmmHasan\InterMix\Tests\Fixture\ClassInit;
use AbmmHasan\InterMix\Tests\Fixture\InjectionOnlyClass;

use function AbmmHasan\InterMix\container;

test('Instance', function () {
    expect(container(InjectionOnlyClass::class, 'injection1'))
        ->toBeInstanceOf(InjectionOnlyClass::class)
        ->and(container(null, 'injection2')->get(InjectionOnlyClass::class))
        ->toBeInstanceOf(InjectionOnlyClass::class);
});

/** @var \AbmmHasan\InterMix\DI\Container $classInit */
$classInit = container(null, 'init_only_1')
    ->setOptions(true, true)
    ->registerClass(ClassInit::class, [
        'abc',
        'def',
        'dbS' => 'ghi'
    ]);
$instance1 = $classInit->call(ClassInit::class);
$randomInitConstructor1 = $instance1['instance']->getValues()['random'];
$instance2 = $classInit->call(ClassInit::class);
$randomInitConstructor2 = $instance2['instance']->getValues()['random'];
$instance3 = $classInit->make(ClassInit::class, 'getValues');
$randomInitConstructor3 = $instance3['random'];
$instance4 = $classInit->make(ClassInit::class, 'getValues');
$randomInitConstructor4 = $instance4['random'];
$instance5 = $classInit->call(ClassInit::class);
$randomInitConstructor5 = $instance5['instance']->getValues()['random'];

test(
    'Singleton Instance',
    function () use ($randomInitConstructor1, $randomInitConstructor2, $randomInitConstructor5) {
        expect($randomInitConstructor1)->toEqual($randomInitConstructor2)
            ->and($randomInitConstructor5)->toEqual($randomInitConstructor2);
    }
);

test(
    'Separate instance via make',
    function () use ($randomInitConstructor1, $randomInitConstructor3, $randomInitConstructor4) {
        expect($randomInitConstructor1)->not->toEqual($randomInitConstructor3)
            ->and($randomInitConstructor3)->not->toEqual($randomInitConstructor4)
            ->and($randomInitConstructor4)->not->toEqual($randomInitConstructor1);
    }
);
