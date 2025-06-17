<?php

use Infocyph\InterMix\DI\Container;
use Infocyph\InterMix\Tests\Fixture\ListenerA;
use Infocyph\InterMix\Tests\Fixture\ListenerB;

it('collects services by tag', function () {
    $c = Container::instance('intermix');

    $c->definitions()->bind('A', fn () => new ListenerA(), tags: ['event']);
    $c->definitions()->bind('B', fn () => new ListenerB(), tags: ['event']);

    $all = $c->findByTag('event');   // method defined in Container :contentReference[oaicite:0]{index=0}
    expect($all)->toHaveCount(2)
        ->and($all['A']())->toBe('A')
        ->and($all['B']())->toBe('B');
});
