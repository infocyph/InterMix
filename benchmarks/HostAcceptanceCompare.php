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
        $baselineLong = self::readMany(self::requiredOption($arguments, 'baseline-long'));
        $currentLong = self::readMany(self::requiredOption($arguments, 'current-long'));

        self::assertComparable($baseline, $current, $baselineLong, $currentLong);
        self::assertCorrect($baseline);
        self::assertCorrect($current);
        self::assertCorrect([...$baselineLong, ...$currentLong]);
        self::assertLongDuration($baselineLong, $currentLong);

        $baselineRps = self::median(array_column($baseline, 'rps'));
        $currentRps = self::median(array_column($current, 'rps'));
        $pairRegressions = [];
        foreach ($baseline as $index => $baselineResult) {
            $baselinePairRps = (float) $baselineResult['rps'];
            $currentPairRps = (float) $current[$index]['rps'];
            $pairRegressions[] = (($baselinePairRps / max($currentPairRps, 0.000001)) - 1.0) * 100.0;
        }
        $regression = self::median($pairRegressions);

        $longRegressions = [];
        foreach ($baselineLong as $index => $baselineLongResult) {
            $baselineLongRps = (float) $baselineLongResult['rps'];
            $currentLongRps = (float) $currentLong[$index]['rps'];
            $longRegressions[] = (($baselineLongRps / max($currentLongRps, 0.000001)) - 1.0) * 100.0;
        }
        $longRegression = self::median($longRegressions);
        $baselineLongRps = self::median(array_column($baselineLong, 'rps'));
        $currentLongRps = self::median(array_column($currentLong, 'rps'));
        $maxRegression = self::floatOption($arguments, 'max-rpm-regression', 2.0);

        $baselineP99 = max(array_map(
            static fn(mixed $value): float => (float) $value,
            array_column($baselineLong, 'p99_ms'),
        ));
        $currentP99 = max(array_map(
            static fn(mixed $value): float => (float) $value,
            array_column($currentLong, 'p99_ms'),
        ));
        $baselineRss = max(array_map(
            static fn(mixed $value): int => (int) $value,
            array_column($baselineLong, 'rss_peak_bytes'),
        ));
        $currentRss = max(array_map(
            static fn(mixed $value): int => (int) $value,
            array_column($currentLong, 'rss_peak_bytes'),
        ));
        $baselinePhp = max(array_map(
            static fn(mixed $value): int => (int) $value,
            array_column($baselineLong, 'php_memory_peak_bytes'),
        ));
        $currentPhp = max(array_map(
            static fn(mixed $value): int => (int) $value,
            array_column($currentLong, 'php_memory_peak_bytes'),
        ));

        $p99Ceiling = max(0.001, $baselineP99 * 1.15);
        $rssCeiling = $baselineRss + max(8 * 1024 * 1024, (int) ($baselineRss * 0.15));
        $phpCeiling = $baselinePhp + max(4 * 1024 * 1024, (int) ($baselinePhp * 0.15));

        printf(
            "PHP %s host acceptance [%s], concurrency %d\n"
            . "median successful RPM: %.2f -> %.2f (paired median %+.2f%% regression, max %.2f%%)\n"
            . "five-minute paired median RPM: %.2f -> %.2f (%+.2f%% regression, max %.2f%%)\n"
            . "long-run worst p99: %.6f ms -> %.6f ms (candidate ceiling %.6f ms)\n"
            . "long-run peak RSS: %d -> %d bytes (candidate ceiling %d)\n"
            . "long-run PHP peak: %d -> %d bytes (candidate ceiling %d)\n",
            (string) $currentLong[0]['php'],
            (string) $currentLong[0]['mode'],
            (int) $currentLong[0]['concurrency'],
            $baselineRps * 60,
            $currentRps * 60,
            $regression,
            $maxRegression,
            $baselineLongRps * 60,
            $currentLongRps * 60,
            $longRegression,
            $maxRegression,
            $baselineP99,
            $currentP99,
            $p99Ceiling,
            $baselineRss,
            $currentRss,
            $rssCeiling,
            $baselinePhp,
            $currentPhp,
            $phpCeiling,
        );

        if ($regression > $maxRegression) {
            throw new RuntimeException('Representative host paired RPM regression budget exceeded.');
        }
        if ($longRegression > $maxRegression) {
            throw new RuntimeException('Representative host five-minute RPM regression budget exceeded.');
        }
        if ($currentP99 > $p99Ceiling) {
            throw new RuntimeException('Representative host p99 latency ceiling exceeded.');
        }
        if ($currentRss > $rssCeiling) {
            throw new RuntimeException('Representative host RSS ceiling exceeded.');
        }
        if ($currentPhp > $phpCeiling) {
            throw new RuntimeException('Representative host PHP memory ceiling exceeded.');
        }
    }

    /**
     * @param list<array<string, mixed>> $baseline
     * @param list<array<string, mixed>> $current
     * @param list<array<string, mixed>> $baselineLong
     * @param list<array<string, mixed>> $currentLong
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
        if (count($baselineLong) < 2 || count($currentLong) < 2) {
            throw new RuntimeException('At least two five-minute baseline/candidate pairs are required.');
        }
        if (count($baselineLong) !== count($currentLong)) {
            throw new RuntimeException('Five-minute baseline and candidate samples must form complete pairs.');
        }

        $php = $baselineLong[0]['php'] ?? null;
        $concurrency = $baselineLong[0]['concurrency'] ?? null;
        $mode = $baselineLong[0]['mode'] ?? null;
        foreach ([...$baseline, ...$current, ...$baselineLong, ...$currentLong] as $result) {
            if (($result['php'] ?? null) !== $php
                || ($result['concurrency'] ?? null) !== $concurrency
                || ($result['mode'] ?? null) !== $mode
            ) {
                throw new RuntimeException('Host acceptance results must use the same PHP version and concurrency.');
            }
        }
    }

    /**
     * @param list<array<string, mixed>> $baseline
     * @param list<array<string, mixed>> $current
     */
    private static function assertLongDuration(array $baseline, array $current): void
    {
        foreach ([...$baseline, ...$current] as $result) {
            if ((float) ($result['duration_seconds'] ?? 0.0) < 300.0) {
                throw new RuntimeException('Five-minute host evidence must measure at least 300 seconds.');
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
        $count = count($normalized);
        if ($count === 0) {
            throw new RuntimeException('Cannot calculate a median from an empty sample.');
        }

        $middle = intdiv($count, 2);
        if (($count % 2) === 1) {
            return $normalized[$middle];
        }

        return ($normalized[$middle - 1] + $normalized[$middle]) / 2;
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
            'duration_seconds',
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
