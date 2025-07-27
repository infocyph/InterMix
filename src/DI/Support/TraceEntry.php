<?php

declare(strict_types=1);

namespace Infocyph\InterMix\DI\Support;

use DateTimeImmutable;

/**
 * A single trace event recorded by {@see DebugTracer}.
 *
 * All properties are readonly – once the entry is created it can be shipped
 * around or serialised without further mutation or locking.
 */
final readonly class TraceEntry
{
    public function __construct(
        public TraceLevel $level,
        public string $message,
        public array $context,
        public DateTimeImmutable $timestamp,
        public ?string $file,
        public int $line,
        public int $memory = 0,
        public int $hrtime = 0,
    ) {
    }

    /**
     * Convenience helper – seconds (float) elapsed since another event.
     */
    public function timeSince(self $earlier): float
    {
        return ($this->hrtime - $earlier->hrtime) / 1_000_000_000;
    }
}
