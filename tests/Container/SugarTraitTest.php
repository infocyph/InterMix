<?php

use Infocyph\InterMix\DI\Container;
use Infocyph\InterMix\Tests\Fixture\DummyLogger;

it('supports property / array / callable sugar on the container', function () {
    /** fresh alias so each run is isolated */
    $c = Container::instance(uniqid('cs_'));

    // (1) property assignment → definition
    $c->logger = fn () => new DummyLogger();

    // (2) array assignment → definition
    $c['cfg'] = fn () => ['debug' => true, 'dsn' => 'mysql://dummy'];

    // ---------- retrieval paths ----------
    $viaCallObject   = $c('logger');   // __invoke
    $viaMagicGet     = $c->logger;     // __get
    $viaArrayGet     = $c['logger'];   // ArrayAccess

    expect($viaCallObject)
        ->toBeInstanceOf(DummyLogger::class)
        ->and($viaMagicGet)->toBe($viaCallObject)
        ->and($viaArrayGet)->toBe($viaCallObject)
        ->and($c('cfg'))
        ->toHaveKey('debug', true)
        ->and($c['cfg'])->toBe($c('cfg'))
        ->and($c->cfg)->toBe($c('cfg'));
});
