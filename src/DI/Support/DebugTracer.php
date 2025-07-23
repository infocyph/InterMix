<?php

namespace Infocyph\InterMix\DI\Support;

final class DebugTracer
{
    private array $stack = [];
    private bool $enabled = false;

    /**
     * Create a new DebugTracer instance with the given trace level.
     *
     * The trace level determines which messages are allowed to be pushed
     * onto the stack. If the level is {@see TraceLevel::Node}, only "node"
     * messages are allowed. If the level is {@see TraceLevel::Verbose},
     * all messages are allowed.
     *
     * @param TraceLevel $level The trace level for the DebugTracer.
     *                          Defaults to {@see TraceLevel::Node}.
     */
    public function __construct(private TraceLevel $level = TraceLevel::Off)
    {
    }

    /**
     * Retrieves the current trace level.
     *
     * @return TraceLevel The trace level which determines which messages are allowed.
     */
    public function level(): TraceLevel
    {
        return $this->level;
    }

    /**
     * Update the trace level of the DebugTracer.
     *
     * Calling this method will set the trace level for all subsequent
     * {@see push()} calls. Note that this does not affect previously
     * pushed messages.
     *
     * @param TraceLevel $level The trace level to set.
     */
    public function setLevel(TraceLevel $level): void
    {
        $this->enabled = $level !== TraceLevel::Off;
        $this->level = $level;
    }

    /**
     * Add a message to the top of the trace stack if the current
     * {@see TraceLevel} allows messages of the given level.
     *
     * @param string $msg The trace message to add.
     * @param TraceLevel $lvl [optional] The level of the trace message.
     *                        Defaults to {@see TraceLevel::Node}.
     */
    public function push(string $msg, TraceLevel $lvl = TraceLevel::Node): void
    {
        if (!$this->enabled || $lvl > $this->level) {
            return;
        }
        $this->stack[] = $msg;
    }

    /**
     * Discard all previously recorded trace messages and return them as an array.
     * This is useful for testing and debugging.
     *
     * @return array The collected trace messages (stack is cleared after this call).
     */
    public function flush(): array
    {
        $trace = $this->stack;
        $this->stack = [];
        return $trace;
    }
}
