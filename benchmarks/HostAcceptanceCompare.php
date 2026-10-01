<?php

declare(strict_types=1);

namespace Infocyph\InterMix\Benchmarks;

use RuntimeException;

final class HostAcceptanceCompare
{
    /** @param list<string> $arguments */
    public static function main(array $arguments): void
    {
        $soak = self::option($arguments, 'assert-soak');
        if ($soak !== null) {
            self::assertSoak(
                self::read($soak),
                self::floatOption($arguments, 'max-rss-growth-mb', 8.0),
                self::floatOption($arguments, 'max-php-growth-mb', 4.0),
            );

            return;
        }

        $baseline = self::readMany(self::requiredOption($arguments, 'baseline'));
        $current = self::readMany(self::requiredOption($arguments, 'current'));
        $baselineLong = self::read(self::requiredOption($arguments, 'baseline-long'));
        $currentLong = self::read(self::requiredOption($arguments, 'current-long'));

        self::assertComparable($baseline, $current, $baselineLong, $currentLong);
        self::assertCorrect($baseline);
        self::assertCorrect($current);
        self::assertCorrect([$baselineLong, $currentLong]);

        $baselineRps = self::median(array_column($baseline, 'rps'));
        $currentRps = self::median(array_column($current, 'rps'));
        $pairRegressions = [];
        foreach ($baseline as $index => $baselineResult) {
            $baselinePairRps = (float) $baselineResult['rps'];
            $currentPairRps = (float) $current[$index]['rps'];
            $pairRegressions[] = (($baselinePairRps / max($currentPairRps, 0.000001)) - 1.0) * 100.0;
        }
        $regression = self::median($pairRegressions);
        $longRegression = (
            ((float) $baselineLong['rps'] / max((float) $currentLong['rps'], 0.000001)) - 1.0
        ) * 100.0;
        $maxRegression = self::floatOption($arguments, 'max-rpm-regression', 2.0);

        $p99Ceiling = max(0.001, (float) $baselineLong['p99_ms'] * 1.15);
        $rssCeiling = (int) $baselineLong['rss_peak_bytes']
            + max(8 * 1024 * 1024, (int) ((int) $baselineLong['rss_peak_bytes'] * 0.15));
        $phpCeiling = (int) $baselineLong['php_memory_peak_bytes']
            + max(4 * 1024 * 1024, (int) ((int) $baselineLong['php_memory_peak_bytes'] * 0.15));

        printf(
            "PHP %s host acceptance [%s], concurrency %d\n"
            . "median successful RPM: %.2f -> %.2f (paired median %+.2f%% regression, max %.2f%%)\n"
            . "five-minute successful RPM: %.2f -> %.2f (%+.2f%% regression, max %.2f%%)\n"
            . "long-run p99: %.6f ms -> %.6f ms (candidate ceiling %.6f ms)\n"
            . "long-run peak RSS: %d -> %d bytes (candidate ceiling %d)\n"
            . "long-run PHP peak: %d -> %d bytes (candidate ceiling %d)\n",
            (string) $currentLong['php'],
            (string) $currentLong['mode'],
            (int) $currentLong['concurrency'],
            $baselineRps * 60,
            $currentRps * 60,
            $regression,
            $maxRegression,
            (float) $baselineLong['rpm'],
            (float) $currentLong['rpm'],
            $longRegression,
            $maxRegression,
            (float) $baselineLong['p99_ms'],
            (float) $currentLong['p99_ms'],
            $p99Ceiling,
            (int) $baselineLong['rss_peak_bytes'],
            (int) $currentLong['rss_peak_bytes'],
            $rssCeiling,
            (int) $baselineLong['php_memory_peak_bytes'],
            (int) $currentLong['php_memory_peak_bytes'],
            $phpCeiling,
        );

        if ($regression > $maxRegression) {
            throw new RuntimeException('Representative host paired RPM regression budget exceeded.');
        }
        if ($longRegression > $maxRegression) {
            throw new RuntimeException('Representative host five-minute RPM regression budget exceeded.');
        }
        if ((float) $currentLong['p99_ms'] > $p99Ceiling) {
            throw new RuntimeException('Representative host p99 latency ceiling exceeded.');
        }
        if ((int) $currentLong['rss_peak_bytes'] > $rssCeiling) {
            throw new RuntimeException('Representative host RSS ceiling exceeded.');
        }
        if ((int) $currentLong['php_memory_peak_bytes'] > $phpCeiling) {
            throw new RuntimeException('Representative host PHP memory ceiling exceeded.');
        }
    }

    /**
     * @param list<array<string, mixed>> $baseline
     * @param list<array<string, mixed>> $current
     * @param array<string, mixed> $baselineLong
     * @param array<string, mixed> $currentLong
     */
    private static function assertComparable(
        array $baseline,
        array $current,
        array $baselineLong,
        array $currentLong,
    ): void {
        if (count($baseline) < 5 || count($current) < 5) {
            throw new RuntimeException('At least five baseline/candidate samples are required.');
        }
        if (count($baseline) !== count($current)) {
            throw new RuntimeException('Baseline and candidate host samples must form complete pairs.');
        }

        $php = $baselineLong['php'] ?? null;
        $concurrency = $baselineLong['concurrency'] ?? null;
        $mode = $baselineLong['mode'] ?? null;
        foreach ([...$baseline, ...$current, $currentLong] as $result) {
            if (($result['php'] ?? null) !== $php
                || ($result['concurrency'] ?? null) !== $concurrency
                || ($result['mode'] ?? null) !== $mode
            ) {
                throw new RuntimeException('Host acceptance results must use the same PHP version and concurrency.');
            }
        }
    }

    private static function assertCorrect(array $results): void
    {
        foreach ($results as $result) {
            if ((int) ($result['unexpected_failures'] ?? 1) !== 0
                || (int) ($result['wrong_outputs'] ?? 1) !== 0
            ) {
                throw new RuntimeException('Host workload produced unexpected failures or wrong outputs.');
            }
            if ((int) ($result['successful'] ?? 0) < 1) {
                throw new RuntimeException('Host workload produced no successful requests.');
            }
        }
    }

    private static function assertSoak(array $result, float $maxRssGrowthMb, float $maxPhpGrowthMb): void
    {
        self::assertCorrect([$result]);

        if (($result['soak'] ?? false) !== true) {
            throw new RuntimeException('Soak assertion requires a soak result.');
        }
        if ((int) ($result['expected_failures'] ?? 0) < 1
            || (int) ($result['expected_cancellations'] ?? 0) < 1
        ) {
            throw new RuntimeException('Soak did not exercise expected failure and cancellation paths.');
        }
        if ((int) ($result['idle_windows'] ?? 0) < 1) {
            throw new RuntimeException('Soak did not exercise an idle/GC window.');
        }

        $rssLimit = (int) round($maxRssGrowthMb * 1024 * 1024);
        $phpLimit = (int) round($maxPhpGrowthMb * 1024 * 1024);
        $rssGrowth = max(0, (int) ($result['rss_growth_bytes'] ?? PHP_INT_MAX));
        $phpGrowth = max(0, (int) ($result['php_memory_growth_bytes'] ?? PHP_INT_MAX));

        printf(
            "PHP %s soak: successful RPM %.2f, p99 %.6f ms, RSS growth %d/%d, PHP growth %d/%d, failures %d, cancellations %d, idle windows %d\n",
            (string) ($result['php'] ?? 'unknown'),
            (float) ($result['rpm'] ?? 0.0),
            (float) ($result['p99_ms'] ?? 0.0),
            $rssGrowth,
            $rssLimit,
            $phpGrowth,
            $phpLimit,
            (int) ($result['expected_failures'] ?? 0),
            (int) ($result['expected_cancellations'] ?? 0),
            (int) ($result['idle_windows'] ?? 0),
        );

        if ($rssGrowth > $rssLimit) {
            throw new RuntimeException('Persistent-host RSS growth ceiling exceeded.');
        }
        if ($phpGrowth > $phpLimit) {
            throw new RuntimeException('Persistent-host PHP memory growth ceiling exceeded.');
        }
    }

    private static function floatOption(array $arguments, string $name, float $default): float
    {
        $value = self::option($arguments, $name);

        return $value === null ? $default : (float) $value;
    }

    private static function median(array $values): float
    {
        $normalized = array_map(static fn(float|int $value): float => (float) $value, $values);
        sort($normalized, SORT_NUMERIC);

        return $normalized[intdiv(count($normalized), 2)];
    }

    private static function option(array $arguments, string $name): ?string
    {
        $prefix = '--' . $name . '=';
        foreach ($arguments as $argument) {
            if (str_starts_with($argument, $prefix)) {
                return substr($argument, strlen($prefix));
            }
        }

        return null;
    }

    private static function read(string $path): array
    {
        $contents = file_get_contents($path);
        if (!is_string($contents)) {
            throw new RuntimeException("Unable to read acceptance result: {$path}");
        }

        $decoded = json_decode($contents, true, flags: JSON_THROW_ON_ERROR);
        if (!is_array($decoded)) {
            throw new RuntimeException("Invalid acceptance result: {$path}");
        }

        foreach ([
            'php',
            'mode',
            'concurrency',
            'successful',
            'unexpected_failures',
            'wrong_outputs',
            'rps',
            'rpm',
            'p99_ms',
            'rss_peak_bytes',
            'php_memory_peak_bytes',
        ] as $key) {
            if (!array_key_exists($key, $decoded)) {
                throw new RuntimeException("Acceptance result is missing '{$key}': {$path}");
            }
        }

        return $decoded;
    }

    private static function readMany(string $paths): array
    {
        $results = [];
        foreach (array_filter(array_map('trim', explode(',', $paths))) as $path) {
            $results[] = self::read($path);
        }

        return $results;
    }

    private static function requiredOption(array $arguments, string $name): string
    {
        $value = self::option($arguments, $name);
        if ($value === null || $value === '') {
            throw new RuntimeException("--{$name} is required.");
        }

        return $value;
    }
}

if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    HostAcceptanceCompare::main($argv);
}
