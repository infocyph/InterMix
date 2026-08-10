<?php

declare(strict_types=1);

use Infocyph\InterMix\Serializer\ClosureSerializer;

it('round-trips a Closure', function () {
    $closure = ClosureSerializer::unserialize(
        ClosureSerializer::serialize(static fn(): string => 'ok'),
    );

    expect($closure())->toBe('ok');
});

it('round-trips captured scalars objects and nested data', function () {
    $scalar = 7;
    $object = (object) ['value' => 11];
    $nested = ['items' => [['value' => 13]]];

    $closure = ClosureSerializer::unserialize(ClosureSerializer::serialize(
        static fn(): int => $scalar + $object->value + $nested['items'][0]['value'],
    ));

    expect($closure())->toBe(31);
});

it('recognizes only the unsigned InterMix envelope', function () {
    $payload = ClosureSerializer::serialize(static fn(): null => null);

    expect($payload)->toStartWith('imxc1.')
        ->and(ClosureSerializer::isSerialized($payload))->toBeTrue()
        ->and(ClosureSerializer::isSerialized('not-a-payload'))->toBeFalse()
        ->and(ClosureSerializer::isSerialized('imxcs1.signature.payload'))->toBeFalse();
});

it('rejects missing malformed and non-Closure payloads', function (string $payload) {
    expect(fn() => ClosureSerializer::unserialize($payload))
        ->toThrow(InvalidArgumentException::class);
})->with([
    'missing envelope' => 'not-a-payload',
    'empty body' => 'imxc1.',
    'invalid base64' => 'imxc1.*',
    'ordinary serialized value' => 'imxc1.' . base64_encode(serialize(['value'])),
]);

it('round-trips a signed Closure', function () {
    $serializer = ClosureSerializer::signed('test-key');
    $payload = $serializer->serialize(static fn(int $value): int => $value * 2);
    $closure = $serializer->unserialize($payload);

    expect($payload)->toStartWith('imxcs1.')
        ->and($closure(6))->toBe(12);
});

it('rejects an empty signing key', function () {
    expect(fn() => ClosureSerializer::signed(''))
        ->toThrow(InvalidArgumentException::class);
});

it('rejects a wrong signing key and tampering', function () {
    $serializer = ClosureSerializer::signed('correct-key');
    $payload = $serializer->serialize(static fn(): string => 'safe');
    $parts = explode('.', $payload, 3);
    $parts[2][0] = $parts[2][0] === 'A' ? 'B' : 'A';
    $tampered = implode('.', $parts);

    expect(fn() => ClosureSerializer::signed('wrong-key')->unserialize($payload))
        ->toThrow(InvalidArgumentException::class)
        ->and(fn() => $serializer->unserialize($tampered))
        ->toThrow(InvalidArgumentException::class);
});

it('keeps signed and unsigned envelopes separate', function () {
    $unsigned = ClosureSerializer::serialize(static fn(): null => null);
    $signedSerializer = ClosureSerializer::signed('test-key');
    $signed = $signedSerializer->serialize(static fn(): null => null);

    expect(fn() => $signedSerializer->unserialize($unsigned))
        ->toThrow(InvalidArgumentException::class)
        ->and(fn() => ClosureSerializer::unserialize($signed))
        ->toThrow(InvalidArgumentException::class);
});
