<?php
use Infocyph\InterMix\Fence\Multi;

class MultiTraitTest
{
    use Multi;

    public static function resetInstances(): void
    {
        static::$instances = [];
    }

    public static function getInstances(): array
    {
        return static::$instances;
    }
}

beforeEach(function () {
    MultiTraitTest::resetInstances(); // Reset instances before each test.
});

test('it creates unique instances for different keys', function () {
    $instance1 = MultiTraitTest::instance('key1');
    $instance2 = MultiTraitTest::instance('key2');

    expect($instance1)->not->toBe($instance2)
        ->and($instance1)->toBeInstanceOf(MultiTraitTest::class);
});

test('it retrieves the same instance for the same key', function () {
    $instance1 = MultiTraitTest::instance('key1');
    $instance2 = MultiTraitTest::instance('key1');

    expect($instance1)->toBe($instance2);
});

test('it clears all instances', function () {
    MultiTraitTest::instance('key1');
    MultiTraitTest::clearInstances();

    expect(MultiTraitTest::getInstances())->toBeEmpty();
});
