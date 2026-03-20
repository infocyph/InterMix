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
        $decoded = self::tryUnserialize($blob);
        if ($decoded === null) {
            return null;
        }

        $fromItem = self::decodeCacheItem($decoded);
        if ($fromItem !== null) {
            return $fromItem;
        }

        return self::decodeArrayPayload($decoded);
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

    /**
     * @return array{value:mixed,expires:int|null}|null
     */
    private static function decodeArrayPayload(mixed $decoded): ?array
    {
        if (!is_array($decoded)) {
            return null;
        }

        $fromFormatted = self::decodeFormattedPayload($decoded);
        if ($fromFormatted !== null) {
            return $fromFormatted;
        }

        if (array_key_exists('value', $decoded) && array_key_exists('expires', $decoded)) {
            return [
                'value' => $decoded['value'],
                'expires' => self::normalizeExpires($decoded['expires']),
            ];
        }

        return null;
    }

    /**
     * @return array{value:mixed,expires:int|null}|null
     */
    private static function decodeCacheItem(mixed $decoded): ?array
    {
        if (!$decoded instanceof CacheItemInterface) {
            return null;
        }

        return ['value' => $decoded->get(), 'expires' => null];
    }

    /**
     * @param array<string, mixed> $decoded
     * @return array{value:mixed,expires:int|null}|null
     */
    private static function decodeFormattedPayload(array $decoded): ?array
    {
        if (($decoded['__imx_cache'] ?? null) !== self::FORMAT || !array_key_exists('value', $decoded)) {
            return null;
        }

        return [
            'value' => $decoded['value'],
            'expires' => self::normalizeExpires($decoded['expires'] ?? null),
        ];
    }

    private static function normalizeExpires(mixed $expires): ?int
    {
        return is_int($expires) ? $expires : null;
    }

    private static function tryUnserialize(string $blob): mixed
    {
        try {
            return ValueSerializer::unserialize($blob);
        } catch (Throwable) {
            return null;
        }
    }
}
