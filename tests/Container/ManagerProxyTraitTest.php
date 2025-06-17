<?php

use Infocyph\InterMix\DI\Container;

it('offers the same sugar directly on DefinitionManager', function () {
    $c   = Container::instance(uniqid('mp_'));
    $def = $c->definitions();

    // (1) property assignment on the manager
    $def->fooSvc = fn () => new stdClass();

    // (2) array assignment
    $def['barSvc'] = fn () =>  'BAR';

    // ------------- resolve through *container* -------------
    expect($c->fooSvc)
        ->toBeInstanceOf(stdClass::class)
        ->and($c->barSvc)->toBe('BAR')
        ->and($def('fooSvc'))->toBe($c->fooSvc)
        ->and($def->barSvc)->toBe('BAR')
        ->and($def['fooSvc'])->toBeInstanceOf(stdClass::class);

    // ------------- resolve directly through manager -------------
    // ArrayAccess on manager

    // (3) fluent chain keeps working and ends back on Container
    $result = $def
        ->bind('answer', fn () => 42)
        ->options()
        ->enableLazyLoading()
        ->end();

    expect($result)->toBe($c)
        ->and($c->answer)->toBe(42);
});
