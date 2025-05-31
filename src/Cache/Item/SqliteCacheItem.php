<?php

declare(strict_types=1);

namespace Infocyph\InterMix\Cache\Item;

use DateInterval;
use DateTime;
use DateTimeInterface;
use Exception;
use Psr\Cache\CacheItemInterface;
use Infocyph\InterMix\Serializer\ValueSerializer;
use Infocyph\InterMix\Cache\Adapter\SqliteCacheAdapter;

class SqliteCacheItem implements CacheItemInterface
{
    /**
     * Constructs a SqliteCacheItem.
     *
     * @param SqliteCacheAdapter $pool The cache pool that created this item.
     * @param string $key The key (namespace-prefixed) under which this item is known to the pool.
     * @param mixed $value The value to be associated with $key.
     * @param bool $hit Whether this item has already been determined to be a cache hit.
     * @param DateTimeInterface|null $exp The absolute DateTime at which this item should expire.
     */
    public function __construct(
        private readonly SqliteCacheAdapter $pool,
        private string $key,
        private mixed $value = null,
        private bool $hit = false,
        private ?DateTimeInterface $exp = null,
    ) {
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
     * {@inheritdoc}
     *
     * @return mixed
     *   The value associated with $this->key, or the default value provided
     *   to the original factory method.
     */
    public function get(): mixed
    {
        return $this->value;
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
        return $this->hit && (!$this->exp || (new DateTime()) < $this->exp);
    }

    /**
     * Assigns a value to the item.
     *
     * @param mixed $value The value to be associated with $this->key.
     *
     * @return static Returns the current instance for method chaining.
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
     * @return static Returns the current instance for method chaining.
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
     *      - int: Number of seconds from now.
     *      - DateInterval: A valid DateInterval object to be added to the current time.
     *      - null: No expiration is set.
     *
     * @return static Returns the current instance for method chaining.
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
     * Calculate the remaining time-to-live (TTL) in seconds for the cache item.
     *
     * This method returns the number of seconds remaining until the cache item
     * expires. If the item has no expiration date set, it returns null. If the
     * expiration date is in the past, it returns 0.
     *
     * @return int|null The number of seconds until expiration, or null if there is no expiration date.
     */
    public function ttlSeconds(): ?int
    {
        return $this->exp ? max(0, $this->exp->getTimestamp() - time()) : null;
    }


    /**
     * Immediately saves the cache item to the SQLite store.
     *
     * This method ensures that the cache item is persisted to the SQLite store
     * without delay, bypassing any deferred queue mechanisms.
     *
     * @return static Returns the current instance for method chaining.
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
     * Serializes the current state of the cache item into an array.
     *
     * @return array An associative array containing:
     *               - 'key': string, the cache key.
     *               - 'value': mixed, the serialized value to be unwrapped.
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
     * Restores the object's state from the given serialized data array.
     *
     * @param array $data Associative array containing:
     *                    - 'key': string, the cache key.
     *                    - 'value': mixed, the serialized value to be unwrapped.
     *                    - 'hit': bool, the cache hit status.
     *                    - 'exp': string|null, the expiration date in ATOM format, if set.
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
