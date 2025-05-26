<?php

namespace Infocyph\InterMix\Cache\Adapter;

use DateInterval;
use DateTime;
use DateTimeInterface;
use Exception;
use Infocyph\InterMix\Cache\CachePool;
use Infocyph\InterMix\Exceptions\CacheInvalidArgumentException;
use Psr\Cache\CacheItemInterface;

class FileCacheAdapter implements CacheItemInterface
{
    public function __construct(
        private readonly CachePool $pool,
        private string $key,
        private mixed $value = null,
        private bool $hit = false,
        private ?DateTimeInterface $expiration = null
    ) {
    }

    /**
     * {@inheritDoc}
     */
    public function getKey(): string
    {
        return $this->key;
    }

    /**
     * Retrieves the value of the cache item.
     *
     * @return mixed The value stored in the cache item.
     */
    public function get(): mixed
    {
        return $this->value;
    }

    /**
     * Checks if the cache item is a hit or a miss.
     *
     * A cache item is considered a hit if it is not expired and was not
     * constructed with a value of null. If the cache item has no expiration
     * date, it is considered a hit.
     *
     * @return bool True if the cache item is a hit, false otherwise.
     */
    public function isHit(): bool
    {
        return match (true) {
            ! $this->hit => false,
            $this->expiration === null => true,
            default => (new DateTime()) < $this->expiration,
        };
    }

    /**
     * Sets the value for the cache item and marks it as a hit.
     *
     * @param mixed $value The value to be stored in the cache item.
     *
     * @return static Returns the current instance for method chaining.
     */
    public function set(mixed $value): static
    {
        $this->value = $value;
        $this->hit   = true;
        return $this;
    }

    /**
     * Sets the expiration time for the cache item.
     *
     * @param DateTimeInterface|null $expiration The expiration time, or null for no expiration.
     *
     * @return static
     */
    public function expiresAt(?DateTimeInterface $expiration): static
    {
        if ($expiration !== null && ! $expiration instanceof DateTimeInterface) {
            throw new CacheInvalidArgumentException('Expiration must be null or DateTimeInterface');
        }
        $this->expiration = $expiration;
        return $this;
    }

    /**
     * Sets the expiration time for the cache item.
     *
     * @param int|DateInterval|null $time
     *      The expiration time. If an integer, it is interpreted as the number of
     *      seconds after the present time. If a DateInterval, it is added to the
     *      current time to determine the expiration. If null, the expiration is
     *      set to the maximum possible value.
     *
     * @return static
     *
     * @throws CacheInvalidArgumentException If the time is not an integer, DateInterval or null.
     */
    public function expiresAfter(int|DateInterval|null $time): static
    {
        $this->expiration = match(true) {
            is_int($time) => (new DateTime())->add(new DateInterval("PT{$time}S")),
            $time instanceof DateInterval => (new DateTime())->add($time),
            $time === null => null,
            default => throw new CacheInvalidArgumentException('Time must be integer seconds, DateInterval or null'),
        };
        return $this;
    }

    /**
     * Serialize the cache item into an array.
     *
     * The following keys are always present in the returned array:
     *
     * - `key`: The cache key.
     * - `value`: The cache value.
     * - `hit`: A boolean indicating whether the cache item is a hit.
     *
     * If the cache item has an expiration date, the array will also contain
     * the key `expiration` with the value being the expiration date in the
     * format defined by {@see DateTimeInterface::format()} with the
     * `DateTime::ATOM` format code.
     *
     * @return array<string, mixed>
     */
    public function __serialize(): array
    {
        return [
            'key'        => $this->key,
            'value'      => $this->value,
            'hit'        => $this->hit,
            'expiration' => $this->expiration?->format(DateTimeInterface::ATOM),
        ];
    }

    /**
     * Restores the object from a serialized array.
     *
     * @param array{key: string, value: mixed, hit: bool, expiration?: string|null} $data
     * @throws Exception
     */
    public function __unserialize(array $data): void
    {
        $this->key        = $data['key'];
        $this->value      = $data['value'];
        $this->hit        = $data['hit'];
        $this->expiration = isset($data['expiration'])
            ? new DateTime($data['expiration'])
            : null;
    }

    /**
     * Save the cache item.
     *
     * This method is a shortcut to {@see CacheItemPoolInterface::save()}.
     *
     * @return static The current instance for method chaining.
     */
    public function save(): static
    {
        $this->pool->save($this);
        return $this;
    }


    /**
     * Defer the saving of the cache item.
     *
     * This method is a shortcut to {@see CacheItemPoolInterface::saveDeferred()}.
     *
     * @return static The current instance for method chaining.
     */
    public function saveDeferred(): static
    {
        $this->pool->saveDeferred($this);
        return $this;
    }
}
