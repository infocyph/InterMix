<?php


namespace AbmmHasan\OOF\Remix;


use ArrayAccess;
use ArrayIterator;
use Countable;
use Iterator;
use JsonSerializable;
use Traversable;

final class Arrject  implements ArrayAccess, Iterator, Countable, JsonSerializable
{
    use Overload;

    /**
     * Constructor.
     *
     * @param array $data Initial data
     */
    public function __construct(array $data = [])
    {
        $this->data = $data;
    }

    /**
     * Get the collection of items as a plain array.
     *
     * @return array
     */
    public function toArray(): array
    {
        return array_map(
            function ($value) {
                return $value;
            },
            $this->data
        );
    }

    /**
     * Get all the items in the collection.
     *
     * @return array
     */
    public function all(): array
    {
        return $this->data;
    }

    /**
     * Determine if the collection is empty or not.
     *
     * @return bool
     */
    public function isEmpty(): bool
    {
        return empty($this->data);
    }

    /**
     * Convert the object into something JSON serializable.
     *
     * @return array
     */
    public function jsonSerialize(): array
    {
        return array_map(
            function ($value) {
                if ($value instanceof JsonSerializable) {
                    return $value->jsonSerialize();
                }
                return $value;
            },
            $this->data
        );
    }

    /**
     * Get the collection of items as JSON.
     *
     * @param string|int $options
     * @return string
     */
    public function toJson(string|int $options = 0): string
    {
        return json_encode($this->jsonSerialize(), $options);
    }

    /**
     * Get an iterator for the items.
     *
     * @return ArrayIterator
     */
    public function getIterator(): ArrayIterator
    {
        return new ArrayIterator($this->data);
    }

    /**
     * Convert the collection to its string representation.
     *
     * @return string
     */
    public function __toString()
    {
        return $this->toJson();
    }

    /**
     * Results array of items from Collection or Arrayable.
     *
     * @param mixed $items
     * @return array
     */
    public function getArrayableItems(mixed $items): array
    {
        if (is_array($items)) {
            return $items;
        } elseif ($items instanceof self) {
            return $items->all();
        } elseif ($items instanceof JsonSerializable) {
            return $items->jsonSerialize();
        } elseif ($items instanceof Traversable) {
            return iterator_to_array($items);
        }

        return (array)$items;
    }

    /**
     * Gets an item at the offset.
     *
     * @param string $offset Offset
     * @return mixed Value
     */
    public function offsetGet($offset): mixed
    {
        return $this->data[$offset] ?? null;
    }

    /**
     * Sets an item at the offset.
     *
     * @param string $offset Offset
     * @param mixed $value Value
     */
    public function offsetSet($offset, $value)
    {
        if (is_null($offset)) {
            $this->data[] = $value;
        } else {
            $this->data[$offset] = $value;
        }
    }

    /**
     * Checks if an item exists at the offset.
     *
     * @param string $offset Offset
     * @return bool Item status
     */
    public function offsetExists($offset): bool
    {
        return isset($this->data[$offset]) || array_key_exists($offset, $this->data);
    }

    /**
     * Removes an item at the offset.
     *
     * @param string $offset Offset
     */
    public function offsetUnset($offset)
    {
        unset($this->data[$offset]);
    }

    /**
     * Resets the collection.
     */
    public function rewind()
    {
        reset($this->data);
    }

    /**
     * Gets current collection item.
     *
     * @return mixed Value
     */
    public function current(): mixed
    {
        return current($this->data);
    }

    /**
     * Gets current collection key.
     *
     * @return string|int|null Value
     */
    public function key(): string|int|null
    {
        return key($this->data);
    }

    /**
     * Gets the next collection value.
     *
     * @return mixed Value
     */
    public function next(): mixed
    {
        return next($this->data);
    }

    /**
     * Checks if the current collection key is valid.
     *
     * @return bool Key status
     */
    public function valid(): bool
    {
        return key($this->data) !== null;
    }

    /**
     * Gets the size of the collection.
     *
     * @return int Collection size
     */
    public function count(): int
    {
        return count($this->data);
    }

    /**
     * Gets the item keys.
     *
     * @return array Collection keys
     */
    public function keys(): array
    {
        return array_keys($this->data);
    }

    /**
     * Gets the collection data.
     *
     * @return array Collection data
     */
    public function getData(): array
    {
        return $this->data;
    }

    /**
     * Sets the collection data.
     *
     * @param array $data New collection data
     */
    public function setData(array $data)
    {
        $this->data = $data;
    }

    /**
     * Removes all items from the collection.
     */
    public function clear()
    {
        $this->data = [];
    }
}