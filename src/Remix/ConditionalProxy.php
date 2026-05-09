<?php

declare(strict_types=1);

namespace Infocyph\InterMix\Remix;

class ConditionalProxy
{
    protected ?bool $condition = null;

    protected bool $hasCondition = false;

    protected bool $negateConditionOnCapture = false;

    /**
     * Create a new ConditionalProxy instance.
     */
    public function __construct(
        protected mixed $target,
    ) {}

    /**
     * Proxy a method call to the target.
     *
     * If no condition has been set yet, calls the target method and captures its return value as the condition (optionally negated).
     * If a condition is already set, calls the method only if the condition is truthy; otherwise returns the original target.
     */
    /**
     * @param array<int, mixed> $parameters
     */
    public function __call(string $method, array $parameters): mixed
    {
        if (!$this->hasCondition) {
            $condition = $this->target->{$method}(...$parameters);

            return $this->condition($this->negateConditionOnCapture ? !$condition : (bool) $condition);
        }

        return $this->condition ? $this->target->{$method}(...$parameters) : $this->target;
    }

    /**
     * Proxy access to a property on the target.
     *
     * If no condition has been set yet, captures the target's property value as the condition (optionally negated).
     * If a condition is already set, returns either the property value or the original target based on the condition.
     */
    public function __get(string $key): mixed
    {
        if (!$this->hasCondition) {
            $condition = $this->target->{$key};

            return $this->condition($this->negateConditionOnCapture ? !$condition : (bool) $condition);
        }

        return $this->condition ? $this->target->{$key} : $this->target;
    }

    /**
     * Checks if a property exists on the target when a condition is set and true.
     */
    public function __isset(string $key): bool
    {
        return $this->hasCondition && $this->condition && isset($this->target->{$key});
    }

    /**
     * Sets a property on the target if a condition has been set and is truthy.
     */
    public function __set(string $key, mixed $value): void
    {
        if ($this->hasCondition && $this->condition) {
            $this->target->{$key} = $value;
        }
    }

    /**
     * No-op to prevent dynamic deletes.
     *
     * Prevents dynamic properties from being unset when a condition is set and true.
     */
    public function __unset(string $key): void
    {
        // no-op to prevent dynamic deletes
    }

    /**
     * Set the condition on the proxy.
     *
     * @return $this
     */
    public function condition(bool $condition): static
    {
        $this->condition = $condition;
        $this->hasCondition = true;

        return $this;
    }

    /**
     * Invert the next condition captured from the target.
     *
     * @return $this
     */
    public function negateConditionOnCapture(): static
    {
        $this->negateConditionOnCapture = true;

        return $this;
    }
}
