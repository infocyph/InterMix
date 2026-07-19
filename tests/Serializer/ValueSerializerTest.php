<?php

declare(strict_types=1);

// tests/Serializer/ValueSerializerTest.php

use Infocyph\InterMix\Serializer\ValueSerializer;

beforeEach(function () {
    ValueSerializer::clearResourceHandlers();
    ValueSerializer::setPayloadSigningKey(null);
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

it('verifies signed payloads when signing key is configured', function () {
    ValueSerializer::setPayloadSigningKey('test-signing-key');

    $payload = ['id' => 42, 'cb' => fn () => 'ok'];
    $blob = ValueSerializer::serialize($payload);
    $out = ValueSerializer::unserialize($blob);

    expect($out['id'])->toBe(42)
        ->and(($out['cb'])())->toBe('ok');
});

it('rejects tampered payloads when signing key is configured', function () {
    ValueSerializer::setPayloadSigningKey('test-signing-key');

    $blob = ValueSerializer::serialize(['safe' => true]);
    $tampered = substr_replace($blob, 'x', -1);

    expect(fn () => ValueSerializer::unserialize($tampered))
        ->toThrow(InvalidArgumentException::class);
});

it('rejects an empty signing key at the signed serializer boundary', function () {
    expect(fn () => ValueSerializer::signed(''))
        ->toThrow(InvalidArgumentException::class);
});

it('keeps signed serializer instances isolated from global key state', function () {
    ValueSerializer::setPayloadSigningKey('global-key');
    $signed = ValueSerializer::signed('scoped-key');

    $payload = $signed->encode(['scope' => 'local']);

    expect(ValueSerializer::currentPayloadSigningKey())->toBe('global-key')
        ->and($signed->decode($payload))->toBe(['scope' => 'local'])
        ->and(ValueSerializer::currentPayloadSigningKey())->toBe('global-key')
        ->and(fn () => ValueSerializer::decode($payload))
        ->toThrow(InvalidArgumentException::class);
});

it('requires the exact wrapped-resource marker before restoring', function () {
    ValueSerializer::registerResourceHandler(
        'test-resource',
        static fn(mixed $value): mixed => $value,
        static fn(mixed $value): string => "restored:$value",
    );

    $lookalike = [
        '__wrapped_resource' => 1,
        'type' => 'test-resource',
        'data' => 'payload',
    ];

    expect(ValueSerializer::unwrap($lookalike))->toBe($lookalike);
});
