<?php

declare(strict_types=1);

use Infocyph\InterMix\DI\Container;
use Infocyph\InterMix\DI\Internal\ConfigurationContainer;
use Infocyph\InterMix\Tests\Fixture\EmailService;
use Infocyph\InterMix\Tests\Fixture\InjectionLessClass;

$injectionLess = new ConfigurationContainer('injection_less');
$injectionLess->options()
    ->setOptions(false)
    ->registration()
    ->registerClass(InjectionLessClass::class, [123])
    ->registerMethod(InjectionLessClass::class, 'ilc', [456]);

test('Instance', function () use ($injectionLess) {
    expect($injectionLess->get(InjectionLessClass::class))
        ->toBeInstanceOf(InjectionLessClass::class);
});

$get2 = $injectionLess->getReturn(InjectionLessClass::class);

test('Return', function () use ($get2) {
    expect($get2)->toBeArray();
});

test('Promoted property/Constructor Parameter', function () use ($get2) {
    expect($get2['constructor'])->toBe('123');
});

test('Method parameter', function () use ($get2) {
    expect($get2['method'])->toBe('456');
});

$injectionLessWithProperty = new ConfigurationContainer('injection_less_with_prop');
$injectionLessWithProperty->options()
    ->setOptions(false)
    ->registration()
    ->registerClass(InjectionLessClass::class, [123])
    ->registerMethod(InjectionLessClass::class, 'ilc', [456])
    ->registerProperty(InjectionLessClass::class, ['internalProperty' => 'propSet']);

test('Non-static Property', function () use ($injectionLessWithProperty) {
    $get3 = $injectionLessWithProperty->getReturn(InjectionLessClass::class);
    expect($get3)
        ->toBeArray()
        ->and($get3['internalProperty'])->toBe('propSet');
});

/*
|--------------------------------------------------------------------------
| resolve() (DI ON) — closure with injected service
|--------------------------------------------------------------------------
*/
test('resolve() executes closure with DI on, configuring EmailService before send', function () {
    $ok = resolve(
        function (EmailService $mail) {
            // Configure via method, then send
            $mail->setConfig(['smtp' => 'localhost', 'port' => 25]);
            return $mail->send('john@example.com', 'Hello', 'Body');
        },
        [],
        'helper_resolve_closure'
    );

    expect($ok)->toBeTrue();
});

/*
|--------------------------------------------------------------------------
| direct() (DI OFF) — register class + method in one chain, then resolve
|--------------------------------------------------------------------------
*/
test('DI-off internal configuration path returns the registered method result', function () {
    $runtime = new ConfigurationContainer('helper_direct_chain');
    $runtime->options()
        ->setOptions(false)
        ->registration()
        ->registerClass(InjectionLessClass::class, [123])
        ->registerMethod(InjectionLessClass::class, 'ilc', [456]);

    $ret = $runtime->getReturn(InjectionLessClass::class);

    expect($ret)
        ->toBeArray()
        ->and($ret['constructor'])->toBe('123')
        ->and($ret['method'])->toBe('456');
});

/*
|--------------------------------------------------------------------------
| resolve()/direct() — null spec returns configured container
|--------------------------------------------------------------------------
*/
test('resolve(null) returns a container (DI on)', function () {
    $c = resolve(null, [], 'helper_resolve_null');
    expect($c)->toBeInstanceOf(Container::class);
});

test('direct(null) returns a container (DI off)', function () {
    $c = direct(null, [], 'helper_direct_null');
    expect($c)->toBeInstanceOf(Container::class);
});
test('Static Property', function () {
    $runtime = new ConfigurationContainer('injection_less_with_static_prop');
    $runtime->options()
        ->setOptions(injection: false)
        ->registration()
        ->registerClass(InjectionLessClass::class, [123])
        ->registerMethod(InjectionLessClass::class, 'ilc', [456])
        ->registerProperty(InjectionLessClass::class, ['staticProperty' => 'propSetStatic']);

    $get4 = $runtime->getReturn(InjectionLessClass::class);

    expect($get4)
        ->toBeArray()
        ->and($get4['staticProperty'])
        ->toBe('propSetStatic');
});
