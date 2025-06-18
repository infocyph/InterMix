<?php
use Infocyph\InterMix\DI\Container;
use Infocyph\InterMix\Tests\Fixture\BarService;
use Infocyph\InterMix\Tests\Fixture\FooService;

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
