<?php

declare(strict_types=1);

namespace Infocyph\InterMix\DI\Support;

use Closure;
use DateTimeImmutable;

final class DebugTracer
{
    /**
     * @var array<string, array{
     *   startNs: int,
     *   memStart: int,
     *   name: string,
     *   level: TraceLevelEnum,
     *   context: array<int|string, mixed>,
     *   depth: int
     * }>
     */
    private array $activeSpans = [];

    private bool $enabled;

    /** @var TraceEntry[] */
    private array $entries = [];

    /** @var array<string, array{from:string,to:string,type:string,count:int}> */
    private array $graphEdges = [];

    private int $seq = 0;

    public function __construct(
        private TraceLevelEnum $level = TraceLevelEnum::Off,
        private bool $captureLocation = false,
    ) {
        $this->enabled = $this->level !== TraceLevelEnum::Off;
    }

    /* ----------  spans  -------------------------------------------------- */

    /**
     * @param array<int|string, mixed> $context
     */
    public function beginSpan(
        string $name,
        TraceLevelEnum $lvl = TraceLevelEnum::Node,
        array $context = [],
    ): Closure {
        if (!$this->enabled || $lvl->value > $this->level->value) {
            return fn() => null;
        }
        $id = dechex(++$this->seq);
        $depth = \count($this->activeSpans);

        $this->activeSpans[$id] = [
            'startNs' => (int) hrtime(true),
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
        $this->graphEdges = [];

        return $this;
    }

    /**
     * Export dependency graph.
     *
     * @return array{
     *     nodes: array<int, string>,
     *     edges: array<int, array{from:string,to:string,type:string,count:int}>
     * }
     */
    public function dependencyGraph(bool $clear = false): array
    {
        $edges = array_values($this->graphEdges);
        $nodes = [];

        foreach ($edges as $edge) {
            $nodes[$edge['from']] = true;
            $nodes[$edge['to']] = true;
        }

        $graph = [
            'nodes' => array_keys($nodes),
            'edges' => $edges,
        ];

        if ($clear) {
            $this->graphEdges = [];
        }

        return $graph;
    }

    /**
     * @param array<int|string, mixed> $context
     */
    public function endSpan(?string $spanId, array $context = []): void
    {
        if ($spanId === null) {
            return;
        }
        if (!isset($this->activeSpans[$spanId])) {
            $this->push(
                "◀ end: span {$spanId}",
                TraceLevelEnum::Node,
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
     * Format & clear the log via custom callback.
     */
    public function getFormatted(callable $formatter): mixed
    {
        $out = $formatter($this->entries);
        $this->clear();

        return $out;
    }

    /* --------------------------------------------------------------------- */

    public function level(): TraceLevelEnum
    {
        return $this->level;
    }

    /* --------------------------------------------------------------------- */

    /**
     * @param array<int|string, mixed> $context
     */
    public function push(
        string $message,
        TraceLevelEnum $lvl = TraceLevelEnum::Node,
        array $context = [],
    ): void {
        if (!$this->enabled || $lvl->value > $this->level->value) {
            return;
        }

        [$file, $line] = $this->captureLocation
            ? (function (): array {
                $bt = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 2)[1] ?? [];
                $file = $bt['file'] ?? null;
                $line = $bt['line'] ?? 0;

                return [$file, $line];
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

    /**
     * Record a dependency edge for later graph export.
     */
    public function recordDependency(string $from, string $to, string $type = 'service'): void
    {
        if (!$this->enabled || $from === '' || $to === '') {
            return;
        }

        $edgeKey = $type . '|' . $from . '|' . $to;
        if (!isset($this->graphEdges[$edgeKey])) {
            $this->graphEdges[$edgeKey] = [
                'from' => $from,
                'to' => $to,
                'type' => $type,
                'count' => 0,
            ];
        }

        $this->graphEdges[$edgeKey]['count']++;
    }

    public function setCaptureLocation(bool $enabled): self
    {
        $this->captureLocation = $enabled;

        return $this;
    }

    public function setLevel(TraceLevelEnum $level): self
    {
        $this->level = $level;
        $this->enabled = $level !== TraceLevelEnum::Off;

        return $this;
    }

    /* ----- built-in formatters ------------------------------------------ */

    /**
     * @return array<int, array{
     *   ts: string,
     *   level: string,
     *   msg: string,
     *   ctx: array<int|string, mixed>,
     *   file: ?string,
     *   line: int,
     *   Δus: int
     * }>
     */
    public function toArray(): array
    {
        $out = [];
        $prevMicros = null;

        foreach ($this->entries as $entry) {
            $currentMicros = (int) $entry->timestamp->format('Uu');
            $out[] = [
                'ts' => $entry->timestamp->format('c'),
                'level' => $entry->level->name,
                'msg' => $entry->message,
                'ctx' => $entry->context,
                'file' => $entry->file,
                'line' => $entry->line,
                'Δus' => $prevMicros === null ? 0 : ($currentMicros - $prevMicros),
            ];
            $prevMicros = $currentMicros;
        }

        $this->clear();

        return $out;
    }

    /**
     * Simple colourised CLI dump (clears after use).
     */
    public function toCli(): string
    {
        $palette = [
            TraceLevelEnum::Verbose->value => "\033[2m",
            TraceLevelEnum::Node->value => "\033[36m",
            TraceLevelEnum::Info->value => "\033[32m",
            TraceLevelEnum::Warn->value => "\033[33m",
            TraceLevelEnum::Error->value => "\033[31m",
        ];
        $out = '';
        foreach ($this->entries as $entry) {
            $color = $palette[$entry->level->value] ?? '';
            $timestamp = $entry->timestamp->format('H:i:s.v');
            $out .= sprintf(
                "%s[%s] %-7s %s\033[0m\n",
                $color,
                $timestamp,
                $entry->level->name,
                $entry->message,
            );
        }

        $this->clear();

        return $out;
    }
}
