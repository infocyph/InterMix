<?php

namespace Infocyph\InterMix\DI\Events;

/**
 * A simple event dispatcher that maintains:
 *   - an array of event => [callables]
 *   - dispatch logic to call each listener in order
 */
class EventDispatcher
{
    protected array $eventListeners = [];

    /**
     * Register a listener for a specific event.
     *
     * @param  string   $eventName
     * @param  callable $listener
     * @return void
     */
    public function addListener(string $eventName, callable $listener): void
    {
        $this->eventListeners[$eventName][] = $listener;
    }

    /**
     * Dispatch an event with an optional payload.
     *
     * @param  string $eventName
     * @param  mixed  $payload
     * @return void
     */
    public function dispatch(string $eventName, mixed $payload = null): void
    {
        if (! isset($this->eventListeners[$eventName])) {
            return;
        }

        foreach ($this->eventListeners[$eventName] as $listener) {
            $listener($payload);
        }
    }
}
