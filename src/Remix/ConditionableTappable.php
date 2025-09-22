<?php

namespace Infocyph\InterMix\Remix;

use Closure;

trait ConditionableTappable
{
    /**
     * Invoke the given callback with this instance and return the instance.
     * If no callback is provided, returns a proxy that allows method chaining on this instance
     * while ensuring the original instance is returned.
     *
     * @param  callable|null  $callback  Callback to invoke with this instance.
     * @return $this|TapProxy  The original instance ($this) or a tap proxy if no callback was given.
     */
    public function tap(?callable $callback = null): TapProxy|static
    {
        if (is_null($callback)) {
            return new TapProxy($this);
        }
        $callback($this);
        return $this;
    }

    /**
     * Apply a callback if the given condition is falsy.
     * If no condition and callbacks are provided, returns a proxy object to conditionally chain further calls (inverted).
     *
     * @param  (Closure($this): mixed)|mixed|null  $value    Condition value (or closure that returns it).
     * @param  callable|null  $callback  Callback to apply if condition is falsy.
     * @param  callable|null  $default   Callback to apply if condition is truthy.
     * @return static|mixed    Result of the callback when executed, or $this.
     */
    public function unless(mixed $value = null, ?callable $callback = null, ?callable $default = null)
    {
        $value = $value instanceof Closure ? $value($this) : $value;
        if (func_num_args() === 0) {
            return (new ConditionalProxy($this))->negateConditionOnCapture();
        }
        if (func_num_args() === 1) {
            return (new ConditionalProxy($this))->condition(! $value);
        }
        if (! $value) {
            return $callback($this, $value) ?? $this;
        } elseif ($default) {
            return $default($this, $value) ?? $this;
        }
        return $this;
    }
    /**
     * Apply a callback if the given condition is truthy.
     * If no condition and callbacks are provided, returns a proxy object to conditionally chain further calls.
     *
     * @param  (Closure($this): mixed)|mixed|null  $value    Condition value (or closure that returns it).
     * @param  callable|null  $callback  Callback to apply if condition is truthy.
     * @param  callable|null  $default   Callback to apply if condition is falsy.
     * @return static|mixed    Result of the callback when executed, or static (fluently, if condition is falsy or no callback).
     */
    public function when(mixed $value = null, ?callable $callback = null, ?callable $default = null)
    {
        $value = $value instanceof Closure ? $value($this) : $value;
        if (func_num_args() === 0) {
            return new ConditionalProxy($this);
        }
        if (func_num_args() === 1) {
            return (new ConditionalProxy($this))->condition($value);
        }
        if ($value) {
            return $callback($this, $value) ?? $this;
        } elseif ($default) {
            return $default($this, $value) ?? $this;
        }
        return $this;
    }
}
