<?php

declare(strict_types=1);

namespace Infocyph\InterMix\Serializer;

final readonly class SignedValueSerializer
{
    public function __construct(private string $key) {}

    public function decode(string $payload, bool $base64 = true): mixed
    {
        $previous = ValueSerializer::currentPayloadSigningKey();
        ValueSerializer::setPayloadSigningKey($this->key);

        try {
            return ValueSerializer::decode($payload, $base64);
        } finally {
            ValueSerializer::setPayloadSigningKey($previous);
        }
    }

    public function encode(mixed $value, bool $base64 = true): string
    {
        $previous = ValueSerializer::currentPayloadSigningKey();
        ValueSerializer::setPayloadSigningKey($this->key);

        try {
            return ValueSerializer::encode($value, $base64);
        } finally {
            ValueSerializer::setPayloadSigningKey($previous);
        }
    }
}
