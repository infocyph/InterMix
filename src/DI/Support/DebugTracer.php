<?php

declare(strict_types=1);

namespace Infocyph\InterMix\DI\Support;

use Closure;
use DateTimeImmutable;

final class DebugTracer
{
    /** @var TraceEntry[] */
    private array $entries = [];

    /**
     * span-id ⇒ [
     *   'startNs'  => int,             // hrtime(true)
     *   'memStart' => int,             // bytes
     *   'name'     => string,
     *   'level'    => TraceLevel,
     *   'context'  => array,
     *   'depth'    => int
     * ]
     * @var array<string,array>
     */
    private array $activeSpans = [];

    private bool $enabled;
    private int $seq = 0;

    public function __construct(
        private TraceLevel $level = TraceLevel::Off,
        private bool $captureLocation = false,
    ) {
        $this->enabled = $this->level !== TraceLevel::Off;
    }

    /* --------------------------------------------------------------------- */

    public function level(): TraceLevel
    {
        return $this->level;
    }

    public function setLevel(TraceLevel $level): self
    {
        $this->level = $level;
        $this->enabled = $level !== TraceLevel::Off;
        return $this;
    }

    public function setCaptureLocation(bool $enabled): self
    {
        $this->captureLocation = $enabled;
        return $this;
    }


    /* --------------------------------------------------------------------- */

    public function push(
        string $message,
        TraceLevel $lvl = TraceLevel::Node,
        array $context = [],
    ): void {
        if (!$this->enabled || $lvl->value > $this->level->value) {
            return;
        }

        [$file, $line] = $this->captureLocation
            ? (function (): array {
                $bt = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 2)[1] ?? [];
                return [$bt['file'] ?? null, $bt['line'] ?? 0];
            })()
            : [null, 0];

        $this->entries[] = new TraceEntry(
            $lvl,
            $message,
            $context + ['mem_kb' => round(memory_get_usage() / 1024, 1)],
            new DateTimeImmutable(),
            $file,
            $line,
        );
    }

    /* ----------  spans  -------------------------------------------------- */

    public function beginSpan(
        string $name,
        TraceLevel $lvl = TraceLevel::Node,
        array $context = [],
    ): Closure {
        if (!$this->enabled || $lvl->value > $this->level->value) {
            return fn () => null;
        }
        $id = dechex(++$this->seq);
        $depth = \count($this->activeSpans);

        $this->activeSpans[$id] = [
            'startNs' => hrtime(true),
            'memStart' => memory_get_usage(),
            'name' => $name,
            'level' => $lvl,
            'context' => $context,
            'depth' => $depth,
        ];

        $this->push("▶ start: {$name}", $lvl, ['span_id' => $id, 'depth' => $depth] + $context);

        return function () use ($id, $context): void {
            $this->endSpan($id, $context);
        };
    }

    public function endSpan(?string $spanId, array $context = []): void
    {
        if ($spanId === null) {
            return;
        }
        if (!isset($this->activeSpans[$spanId])) {
            $this->push(
                "◀ end: span {$spanId}",
                TraceLevel::Node,
                ['span_id' => $spanId] + $context,
            );
            return;
        }

        $span = $this->activeSpans[$spanId];
        unset($this->activeSpans[$spanId]);

        $durationUs = intdiv(hrtime(true) - $span['startNs'], 1000);
        $memDeltaKb = round((memory_get_usage() - $span['memStart']) / 1024, 1);

        $this->push(
            "◀ end: {$span['name']}",
            $span['level'],
            [
                'span_id' => $spanId,
                'us' => $durationUs,
                'mem_kb' => $memDeltaKb,
                'depth' => $span['depth'],
            ] + $context,
        );
    }

    /* ----------  access / export  --------------------------------------- */

    /** @return TraceEntry[] */
    public function getEntries(): array
    {
        return $this->entries;
    }

    /**
     * Clear all data (open spans are auto-closed first).
     */
    public function clear(): self
    {
        foreach (array_keys($this->activeSpans) as $openId) {
            $this->endSpan($openId, ['auto_close' => true]);
        }
        $this->entries = [];
        $this->activeSpans = [];
        return $this;
    }

    /**
     * Format & clear the log via custom callback.
     */
    public function getFormatted(callable $formatter): mixed
    {
        $out = $formatter($this->entries);
        $this->clear();
        return $out;
    }

    /* ----- built-in formatters ------------------------------------------ */

    public function toArray(): array
    {
        return $this->getFormatted(function (array $entries): array {
            $out = [];
            $prev = null;
            foreach ($entries as $e) {
                $out[] = [
                    'ts' => $e->timestamp->format('c'),
                    'level' => $e->level->name,
                    'msg' => $e->message,
                    'ctx' => $e->context,
                    'file' => $e->file,
                    'line' => $e->line,
                    'Δus' => $prev ? intdiv(
                        $e->timestamp->format('Uu') - $prev->timestamp->format('Uu'),
                        1,
                    ) : 0,
                ];
                $prev = $e;
            }
            return $out;
        });
    }

    /**
     * Simple colourised CLI dump (clears after use).
     */
    public function toCli(): string
    {
        return $this->getFormatted(function (array $entries): string {
            $palette = [
                TraceLevel::Verbose->value => "\033[2m",   // dim
                TraceLevel::Node->value => "\033[36m",  // cyan
                TraceLevel::Info->value => "\033[32m",  // green
                TraceLevel::Warn->value => "\033[33m",  // yellow
                TraceLevel::Error->value => "\033[31m",  // red
            ];
            $out = '';
            foreach ($entries as $e) {
                $c = $palette[$e->level->value] ?? '';
                $ts = $e->timestamp->format('H:i:s.v');
                $out .= sprintf(
                    "%s[%s] %-7s %s\033[0m\n",
                    $c,
                    $ts,
                    $e->level->name,
                    $e->message,
                );
            }
            return $out;
        });
    }
}
