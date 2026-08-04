<?php

declare(strict_types=1);

namespace Infocyph\InterMix\Remix;

use Closure;

// Public trait consumers live in downstream projects and the excluded test suite.
// @phpstan-ignore trait.unused
trait ConditionableTappable
{
    /**
     * Invoke the given callback with this instance and return the instance.
     * Without a callback, a proxy preserves fluent calls and the original target.
     *
     * @param callable|null $callback Callback to invoke with this instance.
     * @return TapProxy|static The original instance or a tap proxy.
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
     * @param (Closure($this): mixed)|mixed|null $value Condition value (or closure that returns it).
     * @param callable|null $callback Callback to apply if condition is falsy.
     * @param callable|null $default Callback to apply if condition is truthy.
     * @return static|mixed Result of the callback when executed, or $this.
     */
    public function unless(mixed $value = null, ?callable $callback = null, ?callable $default = null)
    {
        $value = $value instanceof Closure ? $value($this) : $value;
        $argumentCount = func_num_args();

        return match (true) {
            $argumentCount === 0
            => (new ConditionalProxy($this))->negateConditionOnCapture(),

            $argumentCount === 1
            => (new ConditionalProxy($this))->condition(!$value),

            !$value && $callback !== null
                => $callback($this, $value) ?? $this,

            $default !== null
                => $default($this, $value) ?? $this,

            default => $this,
        };
    }

    /**
     * Apply a callback if the given condition is truthy.
     * If no condition and callbacks are provided, returns a proxy object to conditionally chain further calls.
     *
     * @param (Closure($this): mixed)|mixed|null $value Condition value (or closure that returns it).
     * @param callable|null $callback Callback to apply if condition is truthy.
     * @param callable|null $default Callback to apply if condition is falsy.
     * @return static|mixed Result of the callback when executed, or static (fluently, if condition is falsy or no callback).
     */
    public function when(mixed $value = null, ?callable $callback = null, ?callable $default = null)
    {
        $value = $value instanceof Closure ? $value($this) : $value;
        if (func_num_args() === 0) {
            return new ConditionalProxy($this);
        }
        if (func_num_args() === 1) {
            return (new ConditionalProxy($this))->condition((bool) $value);
        }
        if ($value && $callback !== null) {
            return $callback($this, $value) ?? $this;
        } elseif ($default) {
            return $default($this, $value) ?? $this;
        }

        return $this;
    }
}
