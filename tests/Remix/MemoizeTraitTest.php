<?php

use Infocyph\InterMix\Memoize\MemoizeTrait;

class MemoStub
{
    use MemoizeTrait;

    public int $hits = 0;

    public function heavy(): int
    {
        return $this->memoize(__METHOD__, function () {
            return ++$this->hits;
        });
    }

    public function clear(): void
    {
        $this->memoizeClear();
    }
}

it('memoize() caches result', function () {
    $m = new MemoStub();
    $first = $m->heavy();
    $second = $m->heavy();
    expect($first)->toBe(1)->and($second)->toBe(1)->and($m->hits)->toBe(1);
});

it('memoizeClear() resets cache', function () {
    $m = new MemoStub();
    $m->heavy();
    $m->clear();
    $m->heavy();
    expect($m->hits)->toBe(2);
});
