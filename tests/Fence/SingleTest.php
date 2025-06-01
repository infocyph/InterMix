<?php
use Infocyph\InterMix\Fence\Single;

class SingleTraitTest
{
    use Single;
}

beforeEach(function () {
    SingleTraitTest::clearInstances();
});

test('it creates only one instance', function () {
    $i1 = SingleTraitTest::instance();
    $i2 = SingleTraitTest::instance();

    expect($i1)->toBe($i2)
        ->and($i1)->toBeInstanceOf(SingleTraitTest::class);
});

test('it clears the singleton instance', function () {
    SingleTraitTest::instance();
    SingleTraitTest::clearInstances();

    expect(SingleTraitTest::countInstances())->toBe(0);
});
