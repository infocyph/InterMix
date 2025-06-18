<?php

use Infocyph\InterMix\DI\Container;
use Infocyph\InterMix\Tests\Fixture\DummyLogger;

/** Full scenario matching the docs */
it('lets me wire and use services in one-liners', function () {
    $c = Container::instance(uniqid('e2e_'));

    $c->logger = fn () => new DummyLogger();
    $c['now'] = fn () => new DateTimeImmutable();

    // the manager can re-use them transparently
    $def = $c->definitions();
    $def->greeter = function () use ($c) {
        $c->logger->log('greeted');
        return 'Hello @ ' . $c->now->format('c');
    };

    $msg = $c->greeter;

    expect($msg)
        ->toStartWith('Hello @ ')
        ->and($c->logger->records)->toHaveCount(1);
});
