<?php
use Infocyph\InterMix\Fence\Multi;

class MultiTraitTest
{
    use Multi;
}

beforeEach(function () {
    MultiTraitTest::clearInstances();
});

test('it creates unique instances for different keys', function () {
    $a = MultiTraitTest::instance('key1');
    $b = MultiTraitTest::instance('key2');

    expect($a)->not->toBe($b)
        ->and($a)->toBeInstanceOf(MultiTraitTest::class);
});

test('it retrieves the same instance for the same key', function () {
    $x = MultiTraitTest::instance('same');
    $y = MultiTraitTest::instance('same');

    expect($x)->toBe($y);
});

test('it clears all instances', function () {
    MultiTraitTest::instance('foo');
    MultiTraitTest::instance('bar');

    MultiTraitTest::clearInstances();

    expect(MultiTraitTest::getInstances())->toBeEmpty();
});
