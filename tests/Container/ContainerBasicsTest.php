<?php

declare(strict_types=1);

use Infocyph\InterMix\DI\Container;
use Infocyph\InterMix\Tests\Fixture\BasicClass;
use Infocyph\InterMix\Tests\Fixture\BarService;
use Infocyph\InterMix\Tests\Fixture\ClassA;
use Infocyph\InterMix\Tests\Fixture\FooService;
use Infocyph\InterMix\Tests\Fixture\InterfaceA;

it('binds & resolves definitions', function () {
    $c = Container::instance('intermix');
    $c->definitions()->bind('answer', 42);

    expect($c->get('answer'))->toBe(42);
});

it('autowires through constructor', function () {
    $c = Container::instance('intermix');
    $bar = $c->make(BarService::class);

    expect($bar)->toBeInstanceOf(BarService::class)
        ->and($bar->foo)->toBeInstanceOf(FooService::class);
});

it('distinguishes explicit definitions from broad resolvability', function () {
    $c = new Container('definition-introspection');
    $definitions = $c->definitions();
    $c->options()
        ->setEnvironment('production')
        ->bindInterfaceForEnv('production', InterfaceA::class, ClassA::class);
    $c->registration()->registerClass(BasicClass::class);
    $c->registration()->registerClosure('registered.callable', static fn(): int => 1);

    expect($c->has(BasicClass::class))->toBeTrue()
        ->and($c->has(InterfaceA::class))->toBeTrue()
        ->and($definitions->has(BasicClass::class))->toBeFalse()
        ->and($definitions->has(InterfaceA::class))->toBeFalse()
        ->and($definitions->has('registered.callable'))->toBeTrue();

    $c->get(BasicClass::class);
    expect($definitions->has(BasicClass::class))->toBeFalse();

    $definitions->bind('explicit.null', null);
    expect($definitions->has('explicit.null'))->toBeTrue();

    $definitions->unbind('explicit.null');
    expect($definitions->has('explicit.null'))->toBeFalse();
});

it('tracks successful resolution independently of definitions and caches', function () {
    $c = new Container('resolution-introspection');
    $c->transient('transient.service', static fn(): object => new stdClass());

    expect($c->isResolved('transient.service'))->toBeFalse()
        ->and($c->definitions()->has('transient.service'))->toBeTrue()
        ->and($c->has('transient.service'))->toBeTrue();

    $c->get('transient.service');
    expect($c->isResolved('transient.service'))->toBeTrue();

    expect($c->isResolved(BarService::class))->toBeFalse()
        ->and($c->isResolved(FooService::class))->toBeFalse();

    $c->make(BarService::class);
    expect($c->isResolved(BarService::class))->toBeTrue()
        ->and($c->isResolved(FooService::class))->toBeTrue()
        ->and($c->definitions()->has(BarService::class))->toBeFalse();

    $c->unbind('transient.service');
    expect($c->isResolved('transient.service'))->toBeTrue()
        ->and($c->definitions()->has('transient.service'))->toBeFalse();
});
