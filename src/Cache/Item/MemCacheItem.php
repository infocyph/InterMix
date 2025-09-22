<?php

declare(strict_types=1);

namespace Infocyph\InterMix\Cache\Item;

use DateInterval;
use DateTime;
use DateTimeInterface;
use Exception;
use Infocyph\InterMix\Cache\Adapter\MemCacheAdapter;
use Infocyph\InterMix\Serializer\ValueSerializer;
use Psr\Cache\CacheItemInterface;

final class MemCacheItem implements CacheItemInterface
{
    /**
     * Constructor.
     *
     * @param MemCacheAdapter $pool The pool that created this item.
     * @param string $key The key (namespace-prefixed) under which this item is known to the pool.
     * @param mixed $value The value to be associated with $key.
     * @param bool $hit Whether this item has already been determined to be a cache hit.
     * @param DateTimeInterface|null $exp The absolute DateTime at which this item should expire.
     */
    public function __construct(
        private readonly MemCacheAdapter $pool,
        private string $key,
        private mixed $value = null,
        private bool $hit = false,
        private ?DateTimeInterface $exp = null,
    ) {
    }


    /**
     * Serializes the current state of the cache item into an array.
     *
     * @return array An associative array containing:
     *               - 'key': string, the cache key.
     *               - 'value': mixed, the cached value.
     *               - 'hit': bool, the cache hit status.
     *               - 'exp': string|null, the expiration date in ATOM format, if set.
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

    /**
     * Sets the expiration time of the cache item relative to the current time.
     *
     * @param int|DateInterval|null $time
     *      - int: number of seconds from now
     *      - DateInterval: valid DateInterval object to be added to the current time
     *      - null: no expiration
     *
     * @return static The current instance for method chaining.
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
     * Sets the expiration time for the cache item.
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
     * {@inheritdoc}
     *
     * @return mixed
     */
    public function get(): mixed
    {
        return $this->value;
    }


    /**
     * Returns the key for the current cache item.
     *
     * The key is loaded by the implementing cache pool. If the key is
     * not set, an empty string is returned instead.
     *
     * @return string
     *   The key for the current cache item.
     */
    public function getKey(): string
    {
        return $this->key;
    }

    /**
     * Confirms if the cache item lookup resulted in a cache hit.
     *
     * Note: This method MUST be idempotent, meaning it is safe to call it
     * multiple times without consequence.
     *
     * @return bool
     *   TRUE if the request resulted in a cache hit, FALSE otherwise.
     */
    public function isHit(): bool
    {
        if (!$this->hit) {
            return false;
        }
        return !$this->exp || (new DateTime()) < $this->exp;
    }


    /**
     * Saves the cache item to the cache.
     *
     * @return static The current item.
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
     * @return static
     */
    public function saveDeferred(): static
    {
        $this->pool->internalQueue($this);
        return $this;
    }

    /**
     * Assigns a value to the item.
     *
     * @param mixed $value
     *   The value to be associated with $key.
     *
     * @return static
     *   The current object for fluent API.
     */
    public function set(mixed $value): static
    {
        $this->value = ValueSerializer::wrap($value);
        $this->hit = true;
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
}
