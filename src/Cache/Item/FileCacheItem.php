<?php

declare(strict_types=1);

namespace Infocyph\InterMix\Cache\Item;

use DateInterval;
use DateTime;
use DateTimeInterface;
use Exception;
use Infocyph\InterMix\Serializer\ValueSerializer;
use Psr\Cache\CacheItemInterface;
use Infocyph\InterMix\Cache\Adapter\FileCacheAdapter;

class FileCacheItem implements CacheItemInterface
{
    /**
     * FileCacheItem constructor.
     *
     * @param FileCacheAdapter $pool     The pool that created this item.
     * @param string           $key      The key (namespace-prefixed) under which this item is known to the pool.
     * @param mixed            $value    The value to be associated with $key.
     * @param bool             $hit      Whether this item has already been determined to be a cache hit.
     * @param DateTimeInterface|null $exp The absolute DateTime at which this item should expire.
     */
    public function __construct(
        private readonly FileCacheAdapter $pool,
        private string $key,
        private mixed $value = null,
        private bool $hit = false,
        private ?DateTimeInterface $exp = null,
    ) {
    }


    /**
     * Retrieves the key associated with this cache item.
     *
     * @return string The key for this cache item.
     */
    public function getKey(): string
    {
        return $this->key;
    }

    /**
     * @inheritDoc
     * @return mixed
     */
    public function get(): mixed
    {
        return $this->value;
    }

    /**
     * Checks if the item exists in the cache and has not expired.
     *
     * This method is part of the PSR-6 cache interface.
     *
     * @return bool
     *   TRUE if the item exists in the cache and has not expired, FALSE otherwise.
     */
    public function isHit(): bool
    {
        if (!$this->hit) {
            return false;
        }
        return $this->exp === null || (new DateTime()) < $this->exp;
    }

    /**
     * Assigns a value to the item.
     *
     * @param mixed $value
     *
     * @return static
     *   The current object for fluent API
     */
    public function set(mixed $value): static
    {
        $this->value = ValueSerializer::wrap($value);
        $this->hit = true;
        return $this;
    }

    /**
     * Set the expiration time for the cache item.
     *
     * @param DateTimeInterface|null $expiration The date and time the cache item should expire.
     *
     * @return static
     */
    public function expiresAt(?DateTimeInterface $expiration): static
    {
        $this->exp = $expiration;
        return $this;
    }

    /**
     * Sets the expiration time of the cache item relative to the current time.
     *
     * @param int|DateInterval|null $time
     *      - int: number of seconds
     *      - DateInterval: valid DateInterval object
     *      - null: no expiration
     *
     * @return static
     */
    public function expiresAfter(int|DateInterval|null $time): static
    {
        $this->exp = match (true) {
            is_int($time) => (new DateTime())->add(new DateInterval("PT{$time}S")),
            $time instanceof DateInterval => (new DateTime())->add($time),
            default => null,
        };
        return $this;
    }

    /**
     * Immediately saves the cache item to the filesystem.
     *
     * This method should be used when you want to make sure the cache item is
     * persisted to the filesystem immediately, without waiting for the
     * deferred queue in the cache pool to be processed.
     *
     * @return static Returns the current instance for method chaining.
     */
    public function save(): static
    {
        $this->pool->internalPersist($this);
        return $this;
    }

    /**
     * Queues the current cache item for deferred saving.
     *
     * This method adds the cache item to the internal deferred queue of
     * the associated cache adapter. The item will be persisted later
     * when the commit() method is called on the cache pool.
     *
     * @return static Returns the current instance for method chaining.
     */
    public function saveDeferred(): static
    {
        $this->pool->internalQueue($this);
        return $this;
    }

    /**
     * Serializes the current state of the cache item.
     *
     * @return array An associative array containing the key, serialized value,
     *               hit flag, and expiration date formatted as a string.
     */
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
     * Restores the object state from the given serialized data.
     *
     * @param array $data The serialized data containing the key, value, hit flag, and expiration.
     * @throws Exception
     */
    public function __unserialize(array $data): void
    {
        $this->key = $data['key'];
        $this->value = ValueSerializer::unwrap($data['value']);
        $this->hit = $data['hit'];
        $this->exp = isset($data['exp']) ? new DateTime($data['exp']) : null;
    }
}
