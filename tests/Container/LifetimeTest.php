<?php

use Infocyph\InterMix\DI\Container;
use Infocyph\InterMix\DI\Support\Lifetime;

it('honours singleton vs transient vs scoped lifetimes', function () {
    $c = Container::instance('intermix');

    // singleton
    $c->definitions()->bind('uniq', fn () => new stdClass(), Lifetime::Singleton);
    expect($c->get('uniq'))->toBe($c->get('uniq'));

    // transient
    $c->definitions()->bind('trans', fn () => new stdClass(), Lifetime::Transient);
    expect($c->get('trans'))->not->toBe($c->get('trans'));

    // scoped
    $c->definitions()->bind('scoped', fn () => new stdClass(), Lifetime::Scoped);
    $first = $c->get('scoped');

    $c->getRepository()->setScope('next-request');
    $second = $c->get('scoped');

    expect($first)->not->toBe($second);
});
