<?php

test('No debugging statements', function () {
    expect(['dd', 'dump', 'ray', 'die', 'd'])->each->not()->toBeUsed();
});