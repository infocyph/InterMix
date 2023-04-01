<?php

test('No debugging statements are left in code', function () {
    expect(['dd', 'dump', 'ray', 'die', 'd'])->not()->toBeUsed();
});
