<?php

use Infocyph\InterMix\Fence\Limit;

class LimitTraitTest
{
    use Limit;

    public static function resetInstances(): void
    {
        static::$instances = [];
    }
}

beforeEach(function () {
    LimitTraitTest::setLimit(2);
    LimitTraitTest::resetInstances(); // Reset instances = []; // Reset instances before each test.
});

test('it creates instances up to the defined limit', function () {
    $instance1 = LimitTraitTest::instance('first');
    $instance2 = LimitTraitTest::instance('second');

    expect($instance1)->toBeInstanceOf(LimitTraitTest::class)
        ->and($instance2)->toBeInstanceOf(LimitTraitTest::class);
});

test('it throws an exception when exceeding the limit', function () {
    LimitTraitTest::instance('first');
    LimitTraitTest::instance('second');

    LimitTraitTest::instance('third');
})->throws(Exception::class, 'Instance creation failed: Initialization limit (2 of 2 for LimitTraitTest) exceeded.');

test('it allows setting a new limit', function () {
    LimitTraitTest::setLimit(3);
    LimitTraitTest::instance('first');
    LimitTraitTest::instance('second');
    $instance3 = LimitTraitTest::instance('third');

    expect($instance3)->toBeInstanceOf(LimitTraitTest::class);
});
