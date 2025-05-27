<?php

// src/Cache/Item/FileCacheItem.php

declare(strict_types=1);

namespace Infocyph\InterMix\Cache\Item;

use DateInterval;
use DateTime;
use DateTimeInterface;
use Infocyph\InterMix\Serializer\ValueSerializer;
use Psr\Cache\CacheItemInterface;
use Infocyph\InterMix\Cache\Adapter\FileCacheAdapter;

/**
 * Represents a single cache entry for the filesystem adapter.
 *
 *  • Holds key, value, hit-flag, expiration
 *  • Fluent save() / saveDeferred() call back into the adapter
 *  • No serialization logic here—ValueSerializer is used by the adapter
 */
class FileCacheItem implements CacheItemInterface
{
    public function __construct(private readonly FileCacheAdapter $pool, private string $key, private mixed $value = null, private bool $hit = false, private ?DateTimeInterface $exp = null)
    {
    }

    /* -----------------------------------------------------------------
     *  CacheItemInterface
     * ----------------------------------------------------------------*/
    public function getKey(): string
    {
        return $this->key;
    }

    public function get(): mixed
    {
        return $this->value;
    }

    public function isHit(): bool
    {
        if (!$this->hit) {
            return false;
        }
        return $this->exp === null || (new DateTime()) < $this->exp;
    }

    public function set(mixed $value): static
    {
        $this->value = ValueSerializer::wrap($value);
        $this->hit   = true;
        return $this;
    }

    public function expiresAt(?DateTimeInterface $expiration): static
    {
        $this->exp = $expiration;
        return $this;
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

    /* -----------------------------------------------------------------
     *  Fluent persistence helpers
     * ----------------------------------------------------------------*/
    public function save(): static
    {
        $this->pool->internalPersist($this);
        return $this;
    }

    public function saveDeferred(): static
    {
        $this->pool->internalQueue($this);
        return $this;
    }

    public function __serialize(): array
    {
        return [
            'key'   => $this->key,
            'value' => $this->value,                    // already wrapped
            'hit'   => $this->hit,
            'exp'   => $this->exp?->format(DateTimeInterface::ATOM),
        ];
    }

    public function __unserialize(array $data): void
    {
        $this->key   = $data['key'];
        $this->value = ValueSerializer::unwrap($data['value']);  // restore stream
        $this->hit   = $data['hit'];
        $this->exp   = isset($data['exp']) ? new DateTime($data['exp']) : null;
    }
}
