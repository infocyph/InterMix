<?php

namespace Infocyph\InterMix\Remix;

class ConditionalProxy
{
    /**
     * The evaluated condition result.
     *
     * @var bool|null
     */
    protected ?bool $condition = null;

    /**
     * Whether a condition has been set.
     *
     * @var bool
     */
    protected bool $hasCondition = false;

    /**
     * Whether to negate the first captured condition.
     *
     * @var bool
     */
    protected bool $negateConditionOnCapture = false;

    /**
     * Create a new ConditionalProxy instance.
     *
     * @param  mixed  $target
     * @return void
     */
    public function __construct(
        /**
         * The target object being conditionally operated on.
         */
        protected $target
    ) {
    }

    /**
     * Set the condition on the proxy.
     *
     * @param bool $condition
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

    /**
     * Proxy access to a property on the target.
     *
     * If no condition has been set yet, captures the target's property value as the condition (optionally negated).
     * If a condition is already set, returns either the property value or the original target based on the condition.
     *
     * @param string $key
     * @return mixed
     */
    public function __get(string $key)
    {
        if (! $this->hasCondition) {
            $condition = $this->target->{$key};
            return $this->condition($this->negateConditionOnCapture ? ! $condition : $condition);
        }
        return $this->condition ? $this->target->{$key} : $this->target;
    }

    /**
     * Proxy a method call to the target.
     *
     * If no condition has been set yet, calls the target method and captures its return value as the condition (optionally negated).
     * If a condition is already set, calls the method only if the condition is truthy; otherwise returns the original target.
     *
     * @param string $method
     * @param array $parameters
     * @return mixed
     */
    public function __call(string $method, array $parameters)
    {
        if (! $this->hasCondition) {
            $condition = $this->target->{$method}(...$parameters);
            return $this->condition($this->negateConditionOnCapture ? ! $condition : $condition);
        }
        return $this->condition ? $this->target->{$method}(...$parameters) : $this->target;
    }
}
