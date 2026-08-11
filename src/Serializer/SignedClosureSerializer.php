<?php

declare(strict_types=1);

namespace Infocyph\InterMix\Serializer;

use Closure;
use InvalidArgumentException;
use Throwable;

use function Opis\Closure\{serialize as opis_serialize, unserialize as opis_unserialize};

final readonly class SignedClosureSerializer
{
    private const string HMAC_ALGORITHM = 'sha256';

    private const string PREFIX = 'imxcs1.';

    public function __construct(
        #[\SensitiveParameter]
        private string $key,
        private int $maxPayloadSize = ClosureSerializer::DEFAULT_MAX_PAYLOAD_SIZE,
    ) {
        if ($this->key === '') {
            throw new InvalidArgumentException('Closure signing key cannot be empty.');
        }
        if ($this->maxPayloadSize < 1) {
            throw new InvalidArgumentException('Maximum Closure payload size must be positive.');
        }
    }

    public function serialize(Closure $closure): string
    {
        $serialized = opis_serialize($closure);
        $signature = hash_hmac(self::HMAC_ALGORITHM, $serialized, $this->key, true);

        return self::PREFIX . base64_encode($signature) . '.' . base64_encode($serialized);
    }

    public function unserialize(string $payload): Closure
    {
        if (strlen($payload) > $this->maxPayloadSize) {
            throw new InvalidArgumentException('Signed Closure payload exceeds the configured size limit.');
        }
        if (!str_starts_with($payload, self::PREFIX)) {
            throw new InvalidArgumentException('Signed InterMix Closure payload expected.');
        }

        $envelope = substr($payload, strlen(self::PREFIX));
        $separator = strpos($envelope, '.');
        if ($separator === false) {
            throw new InvalidArgumentException('Invalid signed Closure payload format.');
        }

        $signature = base64_decode(substr($envelope, 0, $separator), true);
        $serialized = base64_decode(substr($envelope, $separator + 1), true);
        if ($signature === false || strlen($signature) !== 32 || $serialized === false || $serialized === '') {
            throw new InvalidArgumentException('Invalid signed Closure payload encoding.');
        }

        $expected = hash_hmac(self::HMAC_ALGORITHM, $serialized, $this->key, true);
        if (!hash_equals($expected, $signature)) {
            throw new InvalidArgumentException('Signed Closure payload verification failed.');
        }

        try {
            $closure = opis_unserialize($serialized);
        } catch (Throwable $throwable) {
            throw new InvalidArgumentException('Invalid signed Closure payload.', previous: $throwable);
        }

        if (!$closure instanceof Closure) {
            throw new InvalidArgumentException('Signed payload did not contain a Closure.');
        }

        return $closure;
    }
}
