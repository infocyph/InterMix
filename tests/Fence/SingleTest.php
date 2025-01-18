<?php
use Infocyph\InterMix\Fence\Single;

class SingleTraitTest
{
    use Single;
    public static function resetInstances(): void
    {
        static::$instance = null;
    }
    public static function getInstance()
    {
        return static::$instance;
    }
}

beforeEach(function () {
    SingleTraitTest::resetInstances(); // Reset instances before each test = null; // Reset the singleton instance.
});

test('it creates only one instance', function () {
    $instance1 = SingleTraitTest::instance();
    $instance2 = SingleTraitTest::instance();

    expect($instance1)->toBe($instance2)
        ->and($instance1)->toBeInstanceOf(SingleTraitTest::class);
});

test('it clears the singleton instance', function () {
    SingleTraitTest::instance();
    SingleTraitTest::clearInstance();

    expect(SingleTraitTest::getInstance())->toBeNull();
});
