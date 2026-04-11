<?php

declare(strict_types=1);

namespace Infocyph\InterMix\Cache\Item;

use DateInterval;
use DateTime;
use DateTimeInterface;
use Exception;
use Infocyph\InterMix\Cache\Adapter\InternalCachePoolInterface;
use Infocyph\InterMix\Serializer\ValueSerializer;
use Psr\Cache\CacheItemInterface;

/**
 * Abstract base class for cache item implementations.
 *
 * This class provides a foundation for building PSR-6 compliant cache items.
 * It handles common functionality like key management, value storage,
 * hit/miss tracking, and expiration handling.
 *
 * Cache items represent individual entries in a cache pool and provide
 * methods for accessing and manipulating their state.
 */
abstract class AbstractCacheItem implements CacheItemInterface
{
    /**
     * Creates a new cache item.
     *
     * @param InternalCachePoolInterface|null $pool The cache pool this item belongs to.
     * @param string $key The cache key for this item.
     * @param mixed $value The cached value.
     * @param bool $hit Whether this item was a cache hit.
     * @param DateTimeInterface|null $exp The expiration time for this item.
     */
    public function __construct(
        private ?InternalCachePoolInterface $pool,
        private string $key,
        private mixed $value = null,
        private bool $hit = false,
        private ?DateTimeInterface $exp = null,
    ) {}

    public function __serialize(): array
    {
        return [
            'key' => $this->key,
            'value' => $this->value,
            'hit' => $this->hit,
            'exp' => $this->exp?->format(DateTimeInterface::ATOM),
        ];
    }

    /**
     * @throws Exception
     */
    public function __unserialize(array $data): void
    {
        $this->key = $data['key'];
        $this->value = ValueSerializer::unwrap($data['value']);
        $this->hit = $data['hit'];
        $this->exp = isset($data['exp']) ? new DateTime($data['exp']) : null;
        $this->pool = null;
    }

    public function expiresAfter(int|DateInterval|null $time): static
    {
        $this->exp = match (true) {
            is_int($time) => (new DateTime())->add(new DateInterval("PT{$time}S")),
            $time instanceof DateInterval => (new DateTime())->add($time),
            default => null,
        };
        return $this;
    }

    public function expiresAt(?DateTimeInterface $expiration): static
    {
        $this->exp = $expiration;
        return $this;
    }

    public function get(): mixed
    {
        return $this->value;
    }

    public function getKey(): string
    {
        return $this->key;
    }

    public function isHit(): bool
    {
        if (!$this->hit) {
            return false;
        }
        return $this->exp === null || (new DateTime()) < $this->exp;
    }

    public function save(): static
    {
        $this->pool?->internalPersist($this);
        return $this;
    }

    public function saveDeferred(): static
    {
        $this->pool?->internalQueue($this);
        return $this;
    }

    public function set(mixed $value): static
    {
        $this->value = ValueSerializer::wrap($value);
        $this->hit = true;
        return $this;
    }

    public function ttlSeconds(): ?int
    {
        return $this->exp ? max(0, $this->exp->getTimestamp() - time()) : null;
    }
}
