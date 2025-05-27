<?php

declare(strict_types=1);

namespace Infocyph\InterMix\Cache\Item;

use DateInterval;
use DateTime;
use DateTimeInterface;
use Psr\Cache\CacheItemInterface;
use Infocyph\InterMix\Serializer\ValueSerializer;
use Infocyph\InterMix\Cache\Adapter\MemCacheAdapter;

/**
 * Item wrapper for Memcache adapter.
 */
class MemCacheItem implements CacheItemInterface
{
    public function __construct(
        private readonly MemCacheAdapter $pool,
        private string $key,
        private mixed $value = null,
        private bool $hit = false,
        private ?DateTimeInterface $exp = null,
    ) {
    }

    /* ── PSR-6 interface ─────────────────────────────────────────── */
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
        return $this->exp ? (new DateTime()) < $this->exp : true;
    }

    public function set(mixed $value): static
    {
        $this->value = ValueSerializer::wrap($value);
        $this->hit = true;
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

    /* ── TTL helper (seconds) ────────────────────────────────────── */
    public function ttlSeconds(): ?int
    {
        return $this->exp ? max(0, $this->exp->getTimestamp() - time()) : null;
    }

    /* ── fluent persistence helpers ─────────────────────────────── */
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

    /* ── custom (un)serialisation for ValueSerializer ───────────── */
    public function __serialize(): array
    {
        return [
            'key' => $this->key,
            'value' => $this->value,
            'hit' => $this->hit,
            'exp' => $this->exp?->format(DateTime::ATOM),
        ];
    }

    public function __unserialize(array $data): void
    {
        $this->key = $data['key'];
        $this->value = ValueSerializer::unwrap($data['value']);
        $this->hit = $data['hit'];
        $this->exp = isset($data['exp']) ? new DateTime($data['exp']) : null;
    }
}
