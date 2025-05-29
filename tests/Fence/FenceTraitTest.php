<?php

use Infocyph\InterMix\Exceptions\LimitExceededException;
use Infocyph\InterMix\Exceptions\RequirementException;
use Infocyph\InterMix\Fence\Fence;

class DummyFence
{
    use Fence;

    // Configure as a keyed multiton with a limit of 2
    public const FENCE_KEYED = true;
    public const FENCE_LIMIT = 2;
}

beforeEach(function () {
    DummyFence::clearInstances();
});

test('dummy fence acts as multiton with limit', function () {
    $a = DummyFence::instance('A');
    $b = DummyFence::instance('B');

    expect($a)->not->toBe($b);

    // third instance should exceed the limit
    DummyFence::instance('C');
})->throws(
    LimitExceededException::class,
    'Instance limit of 2 exceeded for DummyFence'
);

test('requirements enforcement', function () {
    // both extension and class missing
    $constraints = [
        'extensions' => ['nonexistent_ext'],
        'classes'    => ['NonExistentClass'],
    ];

    expect(fn () => DummyFence::instance('X', $constraints))
        ->toThrow(RequirementException::class)
        ->and(fn () => DummyFence::instance('Y', ['extensions' => ['json']]))
        ->not->toThrow(RequirementException::class);  // 'json' is typically loaded
});

test('introspection methods work', function () {
    DummyFence::instance('one');
    DummyFence::instance('two');

    expect(DummyFence::countInstances())->toBe(2)
        ->and(DummyFence::getKeys())->toContain('one', 'two')
        ->and(DummyFence::hasInstance('one'))->toBeTrue()
        ->and(DummyFence::hasInstance('none'))->toBeFalse();
});

test('clearInstances resets state', function () {
    DummyFence::instance('any');
    DummyFence::clearInstances();

    expect(DummyFence::countInstances())->toBe(0);
});
