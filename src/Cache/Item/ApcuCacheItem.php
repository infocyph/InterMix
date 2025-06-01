<?php

declare(strict_types=1);

namespace Infocyph\InterMix\Cache\Item;

use DateInterval;
use DateTime;
use DateTimeInterface;
use Exception;
use Psr\Cache\CacheItemInterface;
use Infocyph\InterMix\Serializer\ValueSerializer;
use Infocyph\InterMix\Cache\Adapter\ApcuCacheAdapter;

class ApcuCacheItem implements CacheItemInterface
{
    /**
     * Constructs an ApcuCacheItem.
     *
     * @param ApcuCacheAdapter $pool The cache pool that created this item.
     * @param string $key The key (namespace-prefixed) under which this item is known to the pool.
     * @param mixed $value The value to be associated with $key.
     * @param bool $hit Whether this item has already been determined to be a cache hit.
     * @param DateTimeInterface|null $exp The absolute DateTime at which this item should expire.
     */
    public function __construct(
        private readonly ApcuCacheAdapter $pool,
        private string $key,
        private mixed $value = null,
        private bool $hit = false,
        private ?DateTimeInterface $exp = null,
    ) {
    }


    /**
     * Retrieves the key for this cache item.
     *
     * @return string The key associated with this cache item.
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
        return !$this->exp || (new DateTime()) < $this->exp;
    }

    /**
     * Assigns a value to the item.
     *
     * @param mixed $value
     *
     * @return static
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
     * Set the expiration time of the item.
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
     * Get the TTL (in seconds) from the current time.
     *
     * Returns the number of seconds until the cache item expires, or null if
     * there is no expiration date.
     *
     * @return int|null number of seconds until expiration, or null if there is no expiration
     */
    public function ttlSeconds(): ?int
    {
        return $this->exp ? max(0, $this->exp->getTimestamp() - time()) : null;
    }


    /**
     * Persists the cache item in the cache pool.
     *
     * Call this if you want to save the cache item immediately, without using
     * the deferred queue.
     *
     * @return static
     */
    public function save(): static
    {
        $this->pool->internalPersist($this);
        return $this;
    }


    /**
     * Queues the current cache item for deferred saving in the cache pool.
     *
     * This method adds the cache item to the internal deferred queue of
     * the cache adapter. The item will not be persisted immediately,
     * but will be saved later when the commit() method is called on the
     * cache pool.
     *
     * @return static Returns the current instance for fluent interface.
     */
    public function saveDeferred(): static
    {
        $this->pool->internalQueue($this);
        return $this;
    }

    /**
     * Custom serialization for ValueSerializer.
     *
     * @return array{
     *     key: string,
     *     value: mixed,
     *     hit: bool,
     *     exp?: string,
     * }
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
     * Custom unserialization for ValueSerializer.
     *
     * @param array{
     *     key: string,
     *     value: mixed,
     *     hit: bool,
     *     exp?: string,
     * } $data
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
