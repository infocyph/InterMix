<?php

namespace Infocyph\InterMix\DI\Reflection;

/**
 * Lightweight LIFO tracer used by all resolvers.
 * push() / pop() do nothing unless $level allows.
 */
final class DebugTracer
{
    private array $stack = [];

    public function __construct(private TraceLevel $level = TraceLevel::Node)
    {
    }

    public function level(): TraceLevel
    {
        return $this->level;
    }

    public function setLevel(TraceLevel $level): void
    {
        $this->level = $level;
    }

    public function push(string $msg, TraceLevel $lvl = TraceLevel::Node): void
    {
        if ($lvl <= $this->level) {
            $this->stack[] = $msg;
        }
    }

    public function pop(): void
    {
        if ($this->stack) {
            array_pop($this->stack);
        }
    }

    /** Returns the collected trace and resets the stack. */
    public function flush(): array
    {
        $trace = $this->stack;
        $this->stack = [];
        return $trace;
    }
}
