<?php

declare(strict_types=1);

namespace Infocyph\InterMix\Cache\Adapter;

use DateTimeImmutable;
use DateTimeInterface;
use Infocyph\InterMix\Serializer\ValueSerializer;
use Psr\Cache\CacheItemInterface;
use Throwable;

final class CachePayloadCodec
{
    private const string FORMAT = 'imx-record-v1';

    /**
     * @return array{value:mixed,expires:int|null}|null
     */
    public static function decode(string $blob): ?array
    {
        try {
            $decoded = ValueSerializer::unserialize($blob);
        } catch (Throwable) {
            return null;
        }

        if ($decoded instanceof CacheItemInterface) {
            return ['value' => $decoded->get(), 'expires' => null];
        }

        if (!is_array($decoded)) {
            return null;
        }

        if (($decoded['__imx_cache'] ?? null) === self::FORMAT && array_key_exists('value', $decoded)) {
            return [
                'value' => $decoded['value'],
                'expires' => self::normalizeExpires($decoded['expires'] ?? null),
            ];
        }

        if (array_key_exists('value', $decoded) && array_key_exists('expires', $decoded)) {
            return [
                'value' => $decoded['value'],
                'expires' => self::normalizeExpires($decoded['expires']),
            ];
        }

        return null;
    }

    public static function encode(mixed $value, ?int $expiresAt): string
    {
        return ValueSerializer::serialize([
            '__imx_cache' => self::FORMAT,
            'value' => $value,
            'expires' => $expiresAt,
        ]);
    }

    /**
     * @return array{ttl:int|null,expiresAt:int|null}
     */
    public static function expirationFromItem(CacheItemInterface $item): array
    {
        $ttl = method_exists($item, 'ttlSeconds') ? $item->ttlSeconds() : null;
        $expiresAt = $ttl === null ? null : time() + $ttl;

        return ['ttl' => $ttl, 'expiresAt' => $expiresAt];
    }

    public static function isExpired(?int $expiresAt, ?int $now = null): bool
    {
        return $expiresAt !== null && $expiresAt <= ($now ?? time());
    }

    public static function toDateTime(?int $expiresAt): ?DateTimeInterface
    {
        return $expiresAt === null ? null : (new DateTimeImmutable())->setTimestamp($expiresAt);
    }

    private static function normalizeExpires(mixed $expires): ?int
    {
        return is_int($expires) ? $expires : null;
    }
}
