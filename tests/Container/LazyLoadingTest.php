<?php
use Infocyph\InterMix\DI\Container;

it('defers initialisation when lazy-loading is enabled', function () {
    $flag = false;
    $c = Container::instance('intermix')->options()->enableLazyLoading();

    $c->definitions()->bind('heavy', function () use (&$flag) {
        $flag = true;
        return 123;
    });

    expect($flag)->toBeFalse();          // nothing executed yet
    $val = $c->end()->get('heavy');
    expect($flag)->toBeTrue()->and($val)->toBe(123);
});
