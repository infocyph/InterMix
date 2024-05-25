<?php

test('No debugging statements', function () {
    expect(['dd', 'dump', 'ray', 'die', 'd', 'eval', 'sleep'])
        ->each
        ->not()
        ->toBeUsed();
});