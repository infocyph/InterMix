<?php

declare(strict_types=1);

namespace Infocyph\InterMix\Serializer;

use InvalidArgumentException;

final readonly class SignedValueSerializer
{
    public function __construct(private string $key)
    {
        if ($this->key === '') {
            throw new InvalidArgumentException('Payload signing key cannot be empty.');
        }
    }

    public function decode(string $payload, bool $base64 = true): mixed
    {
        return ValueSerializer::decodeSigned($payload, $this->key, $base64);
    }

    public function encode(mixed $value, bool $base64 = true): string
    {
        return ValueSerializer::encodeSigned($value, $this->key, $base64);
    }
}
