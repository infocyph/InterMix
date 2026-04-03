<?php

declare(strict_types=1);

use Infocyph\InterMix\DI\Container;

require dirname(__DIR__) . '/vendor/autoload.php';

final readonly class BenchConfig
{
    public function __construct(
        public string $env = 'benchmark',
    ) {
    }
}

final class BenchLogger
{
    public function log(string $message): void
    {
        // Intentionally empty. We only measure object graph/runtime overhead.
    }
}

final readonly class BenchRepository
{
    public function __construct(
        public BenchConfig $config,
        public BenchLogger $logger,
    ) {
    }
}

final readonly class BenchService
{
    public function __construct(
        public BenchRepository $repository,
    ) {
    }

    public function handle(int $value): int
    {
        return $value + 1;
    }
}

function progressPercent(int $completed, int $total, bool $forceNewline = false): void
{
    static $isTty = null;
    static $lastWidth = 0;
    static $lastPercent = -1;

    $total = max(1, $total);
    $percent = (int) floor(($completed / $total) * 100);
    $percent = max(0, min(100, $percent));

    if ($isTty === null) {
        $isTty = function_exists('stream_isatty') ? stream_isatty(STDOUT) : false;
    }
    if ($percent === $lastPercent) {
        if ($forceNewline && $isTty && $lastWidth > 0) {
            echo PHP_EOL;
            $lastWidth = 0;
        }
        return;
    }

    $text = '[progress] ' . $percent . '%';

    if ($isTty) {
        $len = strlen($text);
        $pad = $lastWidth > $len ? str_repeat(' ', $lastWidth - $len) : '';
        echo "\r" . $text . $pad;
        $lastWidth = max($lastWidth, $len);
        if ($forceNewline) {
            echo PHP_EOL;
            $lastWidth = 0;
        }
    } else {
        echo $text . PHP_EOL;
    }

    $lastPercent = $percent;

    if (function_exists('ob_get_level') && ob_get_level() > 0) {
        @ob_flush();
    }
    @flush();
}

/**
 * @return array{ms: float, opsPerSec: float, checksum: int}
 */
function runBench(string $label, int $iterations, callable $operation, ?callable $advance = null): array
{
    $start = hrtime(true);
    $checksum = 0;
    $reported = 0;
    $tick = max(1, intdiv($iterations, 20)); // 5%

    for ($i = 1; $i <= $iterations; $i++) {
        $checksum += (int) $operation($i);

        if ($advance !== null && ($i % $tick === 0 || $i === $iterations)) {
            $advance($i - $reported);
            $reported = $i;
        }
    }

    $elapsedMs = (hrtime(true) - $start) / 1_000_000;
    $opsPerSec = $iterations / max($elapsedMs / 1000, 0.000001);

    return [
        'ms' => $elapsedMs,
        'opsPerSec' => $opsPerSec,
        'checksum' => $checksum,
    ];
}

$hotIterations = max(1, (int) ($_ENV['INTERMIX_BENCH_HOT_ITERATIONS'] ?? getenv('INTERMIX_BENCH_HOT_ITERATIONS') ?: 200000));
$coldIterations = max(1, (int) ($_ENV['INTERMIX_BENCH_COLD_ITERATIONS'] ?? getenv('INTERMIX_BENCH_COLD_ITERATIONS') ?: 25000));

echo '[benchmark] InterMix container benchmark starting' . PHP_EOL;
echo "[benchmark] hot iterations: {$hotIterations}, cold iterations: {$coldIterations}" . PHP_EOL;

$alias = '__intermix_benchmark__';
$container = Container::instance($alias);
$container->options()->setOptions(injection: true)->end();
$container->definitions()->bind('bench.config', static fn (): BenchConfig => new BenchConfig());

// Warm-up container paths before measurement.
$container->get('bench.config');
$container->make(BenchService::class);
$container->call(static fn (BenchService $service): int => $service->handle(1));

$totalUnits = $hotIterations + ($coldIterations * 3);
$completedUnits = 0;

$advance = static function (int $units) use (&$completedUnits, $totalUnits): void {
    $completedUnits += max(0, $units);
    progressPercent($completedUnits, $totalUnits);
};

progressPercent(0, $totalUnits);

$results = [];
$results['singleton get (hot path)'] = runBench(
    'singleton get (hot path)',
    $hotIterations,
    static fn (): int => $container->get('bench.config') instanceof BenchConfig ? 1 : 0,
    $advance,
);

$results['transient make'] = runBench(
    'transient make',
    $coldIterations,
    static fn (int $i): int => $container->make(BenchService::class)->handle($i),
    $advance,
);

$results['closure call with DI'] = runBench(
    'closure call with DI',
    $coldIterations,
    static fn (int $i): int => $container->call(static fn (BenchService $service): int => $service->handle($i)),
    $advance,
);

$results['manual object graph'] = runBench(
    'manual object graph',
    $coldIterations,
    static fn (int $i): int => (new BenchService(new BenchRepository(new BenchConfig(), new BenchLogger())))->handle($i),
    $advance,
);

progressPercent($totalUnits, $totalUnits, true);

echo PHP_EOL . str_repeat('-', 90) . PHP_EOL;
echo sprintf("%-34s %12s %14s %14s %14s", 'case', 'iterations', 'time (ms)', 'ops/sec', 'checksum') . PHP_EOL;
echo str_repeat('-', 90) . PHP_EOL;

foreach ($results as $case => $result) {
    echo sprintf(
        "%-34s %12d %14.2f %14.0f %14d",
        $case,
        str_contains($case, 'hot path') ? $hotIterations : $coldIterations,
        $result['ms'],
        $result['opsPerSec'],
        $result['checksum'],
    ) . PHP_EOL;
}

echo str_repeat('-', 90) . PHP_EOL;
echo '[benchmark] completed' . PHP_EOL;
