<?php

it('retry() eventually succeeds', function () {
    $tries = 0;
    $val   = retry(
        3,
        function () use (&$tries) {
            if (++$tries < 3) {
                throw new RuntimeException('fail');
            }
            return 'ok';
        },
        fn () => true,          // always retry
        delayMs: 0
    );
    expect($val)->toBe('ok')->and($tries)->toBe(3);
});

it('measure() records elapsed time', function () {
    $elapsed = null;
    $val     = measure(fn () => 'done', $elapsed);
    expect($val)->toBe('done')->and($elapsed)->toBeFloat()->toBeGreaterThanOrEqual(0);
});
