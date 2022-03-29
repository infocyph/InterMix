<?php

namespace AbmmHasan\OOF\Remix;

trait Overload
{
    protected array $data = [];

    /**
     * Gets an item.
     *
     * @param string $key Key
     * @return mixed Value
     */
    public function __get(string $key)
    {
        return $this->data[$key] ?? null;
    }

    /**
     * Set an item.
     *
     * @param string $key Key
     * @param mixed $value Value
     */
    public function __set(string $key, mixed $value)
    {
        $this->data[$key] = $value;
    }

    /**
     * Checks if an item exists.
     *
     * @param string $key Key
     * @return bool Item status
     */
    public function __isset(string $key)
    {
        return isset($this->data[$key]) || array_key_exists($key, $this->data);
    }

    /**
     * Removes an item.
     *
     * @param string $key Key
     */
    public function __unset(string $key)
    {
        unset($this->data[$key]);
    }
}