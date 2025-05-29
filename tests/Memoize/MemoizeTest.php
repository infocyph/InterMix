<?php

use Infocyph\InterMix\Memoize\Memoizer;
use Infocyph\InterMix\Memoize\MemoizeTrait;

beforeEach(function () {
    // Reset the shared Memoizer
    Memoizer::instance()->flush();
});

it('memoize() returns Memoizer when called without args', function () {
    $m = memoize();
    expect($m)->toBeInstanceOf(Memoizer::class);
});

it('memoize() caches global callables', function () {
    $fn = fn (int $x): int => $x + 1;

    $a = memoize($fn, [1]);
    $b = memoize($fn, [1]);

    expect($a)->toBe(2)->and($b)->toBe(2);

    $stats = memoize()->stats();
    expect($stats)->toMatchArray([
        'hits'   => 1,
        'misses' => 1,
        'total'  => 2,
    ]);
});

it('remember() returns Memoizer when called with no object', function () {
    $m = remember();
    expect($m)->toBeInstanceOf(Memoizer::class);
});

it('remember() throws without callable when object provided', function () {
    $obj = new stdClass();
    expect(fn () => remember($obj))
        ->toThrow(InvalidArgumentException::class);
});

it('remember() caches per-instance callables', function () {
    $obj = new stdClass();
    $counter = 0;
    $fn = function () use (&$counter) {
        return ++$counter;
    };

    $first  = remember($obj, $fn);
    $second = remember($obj, $fn);

    expect($first)->toBe(1)
        ->and($second)->toBe(1);

    // stats should show one hit, one miss
    $stats = memoize()->stats();
    expect($stats['hits'])->toBe(1)
        ->and($stats['misses'])->toBe(1);
});

it('memoizeTrait caches result within object', function () {
    // Dummy class
    $inst = new class () {
        use MemoizeTrait;
        public int $cnt = 0;
        public function next(): int
        {
            return $this->memoize(__METHOD__, fn () => ++$this->cnt);
        }

        public function clear(): void
        {
            $this->memoizeClear();
        }
    };

    $first  = $inst->next();
    $second = $inst->next();

    expect($first)
        ->toBe(1)->and($second)->toBe(1)
        ->and($inst->cnt)->toBe(1);

    // clear and test again
    $inst->clear();
    $third = $inst->next();
    expect($third)->toBe(2);
});

it('flush() resets all caches', function () {
    memoize(fn () => 'x');
    remember(new stdClass(), fn () => 'y');

    memoize()->flush();

    $stats = memoize()->stats();
    expect($stats)->toMatchArray(['hits' => 0,'misses' => 0,'total' => 0]);
});
