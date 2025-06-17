<?php
use Infocyph\InterMix\DI\Container;
use Infocyph\InterMix\Tests\Fixture\DemoProvider;
use Infocyph\InterMix\Tests\Fixture\FooService;

it('imports a service provider', function () {
    $c = Container::instance('intermix');
    $c->registration()->import(DemoProvider::class);

    expect($c->get(FooService::class))->toBeInstanceOf(FooService::class);
});
