<?php

namespace AbmmHasan\InterMix\Memoize;


use AbmmHasan\InterMix\Fence\Multi;
use WeakMap;

class Cache
{
    use Multi;

    public WeakMap $values;

    protected function __construct()
    {
        $this->values = new WeakMap();
    }

    /**
     * @param object $object
     * @param string $backtraceHash
     * @return bool
     */
    public function has(object $object, string $backtraceHash): bool
    {
        if (!isset($this->values[$object])) {
            return false;
        }
        return array_key_exists($backtraceHash, $this->values[$object]);
    }

    /**
     * @param object $object
     * @param string $backtraceHash
     * @return mixed
     */
    public function get(object $object, string $backtraceHash): mixed
    {
        return $this->values[$object][$backtraceHash];
    }

    /**
     * @param object $object
     * @param string $backtraceHash
     * @param mixed $value
     * @return void
     */
    public function set(object $object, string $backtraceHash, mixed $value): void
    {
        $cached = $this->values[$object] ?? [];
        $cached[$backtraceHash] = $value;
        $this->values[$object] = $cached;
    }

    /**
     * @param object $object
     * @return void
     */
    public function forget(object $object): void
    {
        unset($this->values[$object]);
    }

    /**
     * @return void
     */
    public function flush(): void
    {
        $this->values = new WeakMap();
    }
}
