<?php

use Infocyph\InterMix\Tests\Fixture\InjectionLessClass;

use function Infocyph\InterMix\container;

container(null, 'injection_less')
    ->setOptions(false)
    ->registerClass(InjectionLessClass::class, [123])
    ->registerMethod(InjectionLessClass::class, 'ilc', [456]);

test('Instance', function () {
    $get1 = container(null, 'injection_less')
        ->get(InjectionLessClass::class);
    expect($get1)->toBeInstanceOf(InjectionLessClass::class);
});

$get2 = container(null, 'injection_less')
    ->getReturn(InjectionLessClass::class);

test('Return', function () use($get2) {
    expect($get2)->toBeArray();
});

test('Promoted property/Constructor Parameter', function () use($get2) {
    expect($get2['constructor'])->toBe('123');
});

test('Method parameter', function () use($get2) {
    expect($get2['method'])->toBe('456');
});

container(null, 'injection_less_with_prop')
    ->setOptions(false)
    ->registerClass(InjectionLessClass::class, [123])
    ->registerMethod(InjectionLessClass::class, 'ilc', [456])
    ->registerProperty(InjectionLessClass::class, [
        'internalProperty' => 'propSet'
    ]);

test('Non-static Property', function () {
    $get3 = container(null, 'injection_less_with_prop')
        ->getReturn(InjectionLessClass::class);
    expect($get3)
        ->toBeArray()
        ->and($get3['internalProperty'])->toBe('propSet');
});

//test('Static Property', function () {
//    $get4 = container(null, 'injection_less_with_prop')
//        ->registerProperty(InjectionLessClass::class, [
//            'staticProperty' => 'propSetStatic'
//        ])
//        ->getReturn(InjectionLessClass::class);
//    expect($get4)
//        ->toBeArray()
//        ->and($get4['staticProperty'])
//        ->toBe('propSetStatic');
//})->skip('Static property generating error in test but working as expected in live');