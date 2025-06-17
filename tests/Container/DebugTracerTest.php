<?php

use Infocyph\InterMix\DI\Container;
use Infocyph\InterMix\Tests\Fixture\FooService;

it('collects a readable trace', function () {
    $c = Container::instance('intermix')->options()->enableDebugTracing();
    $c->definitions()->bind(FooService::class, fn () => new FooService());

    $trace = $c->end()->debug(FooService::class);
//dd($trace);
    expect($trace)->toBeArray()
        ->and($trace)->toContain('def:' . FooService::class);
});
