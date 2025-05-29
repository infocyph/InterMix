<?php

use Infocyph\InterMix\Exceptions\LimitExceededException;
use Infocyph\InterMix\Fence\Limit;

class LimitTraitTest
{
    use Limit;
}

beforeEach(function () {
    // Ensure a clean slate before each test
    LimitTraitTest::setLimit(2);
    LimitTraitTest::clearInstances();
});

test('it creates instances up to the defined limit', function () {
    $first  = LimitTraitTest::instance('first');
    $second = LimitTraitTest::instance('second');

    expect($first)->toBeInstanceOf(LimitTraitTest::class)
        ->and($second)->toBeInstanceOf(LimitTraitTest::class);
});

test('it throws an exception when exceeding the limit', function () {
    LimitTraitTest::instance('first');
    LimitTraitTest::instance('second');

    LimitTraitTest::instance('third');
})->throws(
    LimitExceededException::class,
    'Instance limit of 2 exceeded for LimitTraitTest'
);

test('it allows setting a new limit', function () {
    LimitTraitTest::setLimit(3);

    $one   = LimitTraitTest::instance('one');
    $two   = LimitTraitTest::instance('two');
    $three = LimitTraitTest::instance('three');

    expect($three)->toBeInstanceOf(LimitTraitTest::class);
});
