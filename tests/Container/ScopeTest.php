<?php

use Infocyph\InterMix\DI\Container;

it('isolates resolved instances per scope', function () {
    $c = Container::instance('intermix');
    $c->definitions()->bind('obj', fn () => new stdClass(), lifetime: Infocyph\InterMix\DI\Support\Lifetime::Scoped);

    $a = $c->get('obj');
    $c->getRepository()->setScope('child');
    $b = $c->get('obj');

    expect($a)->not->toBe($b);
});
