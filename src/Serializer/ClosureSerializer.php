<?php

declare(strict_types=1);

namespace Infocyph\InterMix\Serializer;

use Closure;
use InvalidArgumentException;
use Throwable;

use function Opis\Closure\{serialize as opis_serialize, unserialize as opis_unserialize};

final class ClosureSerializer
{
    public const int DEFAULT_MAX_PAYLOAD_SIZE = 1_048_576;

    private const string PREFIX = 'imxc1.';

    public static function isEnvelope(string $payload): bool
    {
        return str_starts_with($payload, self::PREFIX);
    }

    public static function serialize(Closure $closure): string
    {
        return self::PREFIX . base64_encode(opis_serialize($closure));
    }

    public static function signed(
        #[\SensitiveParameter]
        string $key,
        int $maxPayloadSize = self::DEFAULT_MAX_PAYLOAD_SIZE,
    ): SignedClosureSerializer {
        return new SignedClosureSerializer($key, $maxPayloadSize);
    }

    public static function unserialize(
        string $payload,
        int $maxPayloadSize = self::DEFAULT_MAX_PAYLOAD_SIZE,
    ): Closure {
        if ($maxPayloadSize < 1) {
            throw new InvalidArgumentException('Maximum Closure payload size must be positive.');
        }
        if (strlen($payload) > $maxPayloadSize) {
            throw new InvalidArgumentException('Unsigned Closure payload exceeds the configured size limit.');
        }
        if (!self::isEnvelope($payload)) {
            throw new InvalidArgumentException('Unsigned InterMix Closure payload expected.');
        }

        $serialized = base64_decode(substr($payload, strlen(self::PREFIX)), true);
        if ($serialized === false || $serialized === '') {
            throw new InvalidArgumentException('Invalid unsigned Closure payload body.');
        }

        try {
            $closure = opis_unserialize($serialized);
        } catch (Throwable $throwable) {
            throw new InvalidArgumentException('Invalid unsigned Closure payload.', previous: $throwable);
        }

        if (!$closure instanceof Closure) {
            throw new InvalidArgumentException('Serialized payload did not contain a Closure.');
        }

        return $closure;
    }
}
