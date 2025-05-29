<?php

use Infocyph\InterMix\Remix\TapProxy;

/* tap with callback --------------------------------------------- */
it('tap() passes value and returns it', function () {
    $msg = tap('hello', fn (&$v) => $v = strtoupper($v));
    expect($msg)->toBe('HELLO');
});

/* TapProxy chaining --------------------------------------------- */
it('TapProxy forwards calls and yields target', function () {
    $obj = new class () {
        public int $n = 0;
        public function add()
        {
            $this->n++;
        }
    };
    tap($obj)->add()->add();
    expect($obj->n)->toBe(2);
});

/* pipe() --------------------------------------------------------- */
it('pipe() transforms value', function () {
    $out = pipe([1,2,3], 'array_sum');
    expect($out)->toBe(6);
});
