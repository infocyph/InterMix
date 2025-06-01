<?php

// tests/Serializer/ValueSerializerTest.php

use Infocyph\InterMix\Serializer\ValueSerializer;

beforeEach(function () {
    ValueSerializer::clearResourceHandlers();
});

it('serialises and unserialises scalars and arrays', function () {
    $values = [
        123,
        'abc',
        [1, 2, 3],
        ['a' => 'x', 'b' => ['nested' => true]],
    ];

    foreach ($values as $v) {
        $blob = ValueSerializer::serialize($v);
        $out  = ValueSerializer::unserialize($blob);
        expect($out)->toBe($v);
    }
});

it('round-trips closures', function () {
    $fn   = fn (int $x): int => $x + 2;
    $blob = ValueSerializer::serialize($fn);
    $rest = ValueSerializer::unserialize($blob);

    expect(is_callable($rest))
        ->toBeTrue()
        ->and($rest(5))->toBe(7);
});

it('wraps and unwraps without full serialization', function () {
    $data    = ['foo' => 'bar', 'baz' => [1, 2, 3]];
    $wrapped = ValueSerializer::wrap($data);
    expect($wrapped)->toBe($data);

    $unwrapped = ValueSerializer::unwrap($wrapped);
    expect($unwrapped)->toBe($data);
});

it('throws when wrapping a resource with no handler', function () {
    $s = fopen('php://memory', 'r+');

    expect(fn () => ValueSerializer::wrap($s))
        ->toThrow(InvalidArgumentException::class)
        ->and(fn () => ValueSerializer::serialize($s))
        ->toThrow(InvalidArgumentException::class);

    fclose($s);
});
