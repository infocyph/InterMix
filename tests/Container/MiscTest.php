<?php

declare(strict_types=1);

use Infocyph\InterMix\DI\ContainerBuilder;
use Infocyph\InterMix\Tests\Fixture\EmailService;
use Infocyph\InterMix\Tests\Fixture\InjectionLessClass;

test('make constructs a fresh root and invoke preserves explicit arguments', function () {
    $runtime = ContainerBuilder::create()->build();

    $first = $runtime->make(InjectionLessClass::class, ['123']);
    $second = $runtime->make(InjectionLessClass::class, ['123']);
    $result = $runtime->invoke([$first, 'ilc'], ['456']);

    expect($first)->toBeInstanceOf(InjectionLessClass::class)
        ->not->toBe($second)
        ->and($result)->toBeArray()
        ->and($result['constructor'])->toBe('123')
        ->and($result['method'])->toBe('456');
});

test('invoke injects missing callable arguments', function () {
    $runtime = ContainerBuilder::create()
        ->autowire(EmailService::class, EmailService::class)
        ->build();

    $ok = $runtime->invoke(function (EmailService $mail): bool {
        $mail->setConfig(['smtp' => 'localhost', 'port' => 25]);

        return $mail->send('john@example.com', 'Hello', 'Body');
    });

    expect($ok)->toBeTrue();
});

test('DI lookup globals are absent', function () {
    expect(function_exists('container'))->toBeFalse()
        ->and(function_exists('resolve'))->toBeFalse()
        ->and(function_exists('direct'))->toBeFalse();
});
