<?php

declare(strict_types=1);

namespace Infocyph\InterMix\Benchmarks;

use Fiber;
use Infocyph\InterMix\DI\ContainerBuilder;
use Infocyph\InterMix\DI\Support\LifetimeEnum;
use ReflectionMethod;
use RuntimeException;
use Throwable;

final class HostAcceptanceLeaf
{
    public string $marker = 'leaf';
}

final class HostAcceptanceRequest
{
    public readonly string $id;

    public function __construct(string $id)
    {
        $this->id = $id;
    }
}

final class HostAcceptanceHandler
{
    private readonly HostAcceptanceRequest $request;

    public function __construct(HostAcceptanceRequest $request)
    {
        $this->request = $request;
    }

    public function handle(HostAcceptanceLeaf $leaf): string
    {
        return $this->request->id . ':' . $leaf->marker;
    }
}

final class HostAcceptance
{
    private const int LATENCY_SAMPLE_LIMIT = 20_000;

    private const int WARMUP_REQUESTS = 2_000;

    /** @param list<string> $arguments */
    public static function main(array $arguments): void
    {
        $autoload = self::requiredOption($arguments, 'autoload');
        $output = self::requiredOption($arguments, 'output');
        $duration = self::floatOption($arguments, 'duration', 30.0);
        $concurrency = self::intOption($arguments, 'concurrency', 1);
        $soak = self::hasFlag($arguments, 'soak');

        if ($duration <= 0.0 || $concurrency < 1) {
            throw new RuntimeException('Duration and concurrency must be positive.');
        }
        if (!is_file($autoload)) {
            throw new RuntimeException("Autoload file is not readable: {$autoload}");
        }

        require $autoload;

        $runtime = self::runtime();
        $calls = self::runtimeCalls($runtime);
        self::warm($calls, $concurrency);

        $result = self::measure($calls, $duration, $concurrency, $soak);
        $encoded = json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
        if (file_put_contents($output, $encoded) === false) {
            throw new RuntimeException("Unable to write host acceptance output: {$output}");
        }

        fwrite(STDOUT, $encoded);
    }

    private static function cancellationProbe(array $calls, int $sequence): int
    {
        $request = new HostAcceptanceRequest('cancel-' . $sequence);
        $fiber = new Fiber(
            static function () use ($calls, $request): mixed {
                return ($calls['scope'])(
                    'request',
                    static function () use ($calls): never {
                        ($calls['get'])(HostAcceptanceHandler::class);
                        Fiber::suspend();

                        throw new RuntimeException('Cancellation probe resumed unexpectedly.');
                    },
                    [HostAcceptanceRequest::class => $request],
                );
            },
        );

        $fiber->start();

        try {
            $fiber->throw(new RuntimeException('host-expected-cancellation'));
        } catch (RuntimeException $exception) {
            if ($exception->getMessage() === 'host-expected-cancellation') {
                return 1;
            }

            throw $exception;
        }

        throw new RuntimeException('Expected cancellation probe did not cancel.');
    }

    private static function failureProbe(array $calls, int $sequence): int
    {
        $request = new HostAcceptanceRequest('failure-' . $sequence);

        try {
            ($calls['scope'])(
                'request',
                static function () use ($calls): never {
                    ($calls['get'])(HostAcceptanceHandler::class);

                    throw new RuntimeException('host-expected-failure');
                },
                [HostAcceptanceRequest::class => $request],
            );
        } catch (RuntimeException $exception) {
            if ($exception->getMessage() === 'host-expected-failure') {
                return 1;
            }

            throw $exception;
        }

        throw new RuntimeException('Expected failure probe did not fail.');
    }

    private static function floatOption(array $arguments, string $name, float $default): float
    {
        $value = self::option($arguments, $name);

        return $value === null ? $default : (float) $value;
    }

    private static function hasFlag(array $arguments, string $name): bool
    {
        return in_array('--' . $name, $arguments, true);
    }

    private static function intOption(array $arguments, string $name, int $default): int
    {
        $value = self::option($arguments, $name);

        return $value === null ? $default : (int) $value;
    }

    private static function invokeSetup(object $target, string $method, array $arguments = []): mixed
    {
        return new ReflectionMethod($target, $method)->invokeArgs($target, $arguments);
    }

    /**
     * @param array{scope: \Closure, get: \Closure, dispatch: \Closure, api: string} $calls
     * @return array<string, int|float|string|bool|list<int>>
     */
    private static function measure(array $calls, float $duration, int $concurrency, bool $soak): array
    {
        $started = hrtime(true);
        $deadline = $started + (int) ($duration * 1_000_000_000);
        $usageStart = self::resourceUsage();
        $rssStart = self::rssBytes();
        $phpStart = memory_get_usage(true);

        $sequence = 0;
        $successful = 0;
        $unexpected = 0;
        $wrong = 0;
        $expectedFailures = 0;
        $expectedCancellations = 0;
        $idleWindows = 0;
        $latencies = [];
        $rssSamples = [$rssStart];
        $lastRssSample = $started;
        $lastSoakProbe = $started;
        $batch = 0;

        while (hrtime(true) < $deadline) {
            $batchStarted = hrtime(true);
            $result = self::runBatch($calls, $concurrency, $sequence);
            $batchEnded = hrtime(true);

            $successful += $result['successful'];
            $unexpected += $result['unexpected'];
            $wrong += $result['wrong'];

            if (($batch % 32) === 0 && count($latencies) < self::LATENCY_SAMPLE_LIMIT) {
                $latencies[] = (($batchEnded - $batchStarted) / 1_000_000) / $concurrency;
            }

            if (($batchEnded - $lastRssSample) >= 5_000_000_000) {
                $rssSamples[] = self::rssBytes();
                $lastRssSample = $batchEnded;
            }

            if ($soak && ($batchEnded - $lastSoakProbe) >= 60_000_000_000) {
                $expectedFailures += self::failureProbe($calls, ++$sequence);
                $expectedCancellations += self::cancellationProbe($calls, ++$sequence);
                gc_collect_cycles();
                usleep(100_000);
                ++$idleWindows;
                $lastSoakProbe = hrtime(true);
            }

            ++$batch;
        }

        gc_collect_cycles();
        $ended = hrtime(true);
        $elapsed = ($ended - $started) / 1_000_000_000;
        $usageEnd = self::resourceUsage();
        $rssEnd = self::rssBytes();
        $rssSamples[] = $rssEnd;
        $phpEnd = memory_get_usage(true);

        $rps = $successful / $elapsed;
        $cpuSeconds = self::usageSeconds($usageEnd) - self::usageSeconds($usageStart);

        sort($latencies, SORT_NUMERIC);

        return [
            'php' => PHP_VERSION,
            'api' => $calls['api'],
            'soak' => $soak,
            'duration_seconds' => $elapsed,
            'concurrency' => $concurrency,
            'successful' => $successful,
            'unexpected_failures' => $unexpected,
            'wrong_outputs' => $wrong,
            'expected_failures' => $expectedFailures,
            'expected_cancellations' => $expectedCancellations,
            'rps' => $rps,
            'rpm' => $rps * 60,
            'p50_ms' => self::percentile($latencies, 0.50),
            'p95_ms' => self::percentile($latencies, 0.95),
            'p99_ms' => self::percentile($latencies, 0.99),
            'cpu_percent' => $elapsed > 0.0 ? ($cpuSeconds / $elapsed) * 100 : 0.0,
            'rss_start_bytes' => $rssStart,
            'rss_end_bytes' => $rssEnd,
            'rss_peak_bytes' => max($rssSamples),
            'rss_growth_bytes' => $rssEnd - $rssStart,
            'php_memory_start_bytes' => $phpStart,
            'php_memory_end_bytes' => $phpEnd,
            'php_memory_peak_bytes' => memory_get_peak_usage(true),
            'php_memory_growth_bytes' => $phpEnd - $phpStart,
            'idle_windows' => $idleWindows,
            'max_queue_depth' => 0,
            'latency_samples' => count($latencies),
            'rss_samples_bytes' => $rssSamples,
        ];
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

    private static function percentile(array $values, float $quantile): float
    {
        if ($values === []) {
            return 0.0;
        }

        return $values[(int) floor((count($values) - 1) * $quantile)];
    }

    private static function requiredOption(array $arguments, string $name): string
    {
        $value = self::option($arguments, $name);
        if ($value === null || $value === '') {
            throw new RuntimeException("--{$name} is required.");
        }

        return $value;
    }

    private static function resourceUsage(): array
    {
        $usage = getrusage();

        return is_array($usage) ? $usage : [];
    }

    private static function rssBytes(): int
    {
        $contents = is_readable('/proc/self/status') ? file_get_contents('/proc/self/status') : false;
        if (is_string($contents)
            && preg_match('/^VmRSS:\s+(\d+)\s+kB$/m', $contents, $matches) === 1
        ) {
            return (int) $matches[1] * 1024;
        }

        return memory_get_usage(true);
    }

    /**
     * @param array{scope: \Closure, get: \Closure, dispatch: \Closure, api: string} $calls
     * @return array{successful: int, unexpected: int, wrong: int}
     */
    private static function runBatch(array $calls, int $concurrency, int &$sequence): array
    {
        $fibers = [];
        $expected = [];

        for ($index = 0; $index < $concurrency; ++$index) {
            $request = new HostAcceptanceRequest('request-' . ++$sequence);
            $expected[$index] = $request->id . ':leaf';
            $fibers[$index] = new Fiber(
                static function () use ($calls, $request): mixed {
                    return ($calls['scope'])(
                        'request',
                        static function () use ($calls): mixed {
                            $handler = ($calls['get'])(HostAcceptanceHandler::class);
                            Fiber::suspend();

                            return ($calls['dispatch'])([$handler, 'handle']);
                        },
                        [HostAcceptanceRequest::class => $request],
                    );
                },
            );
        }

        $unexpected = 0;
        foreach ($fibers as $fiber) {

            try {
                $fiber->start();
            } catch (Throwable) {
                ++$unexpected;
            }
        }

        $successful = 0;
        $wrong = 0;
        foreach ($fibers as $index => $fiber) {
            if ($fiber->isTerminated()) {
                continue;
            }

            try {
                $fiber->resume();
                if ($fiber->getReturn() !== $expected[$index]) {
                    ++$wrong;

                    continue;
                }
                ++$successful;
            } catch (Throwable) {
                ++$unexpected;
            }
        }

        return [
            'successful' => $successful,
            'unexpected' => $unexpected,
            'wrong' => $wrong,
        ];
    }

    private static function runtime(): object
    {
        $builder = ContainerBuilder::create('__host_acceptance_' . bin2hex(random_bytes(6)));

        if (method_exists($builder, 'autowire')) {
            $builder
                ->autowire(HostAcceptanceLeaf::class, HostAcceptanceLeaf::class)
                ->input(HostAcceptanceRequest::class)
                ->autowire(
                    HostAcceptanceHandler::class,
                    HostAcceptanceHandler::class,
                    lifetime: LifetimeEnum::Scoped,
                );

            return $builder->build();
        }

        self::invokeSetup($builder, 'singleton', [HostAcceptanceLeaf::class]);
        self::invokeSetup($builder, 'scoped', [HostAcceptanceHandler::class]);

        $runtime = self::invokeSetup($builder, 'development');
        if (!is_object($runtime)) {
            throw new RuntimeException('InterMix 10.1 builder did not return a runtime.');
        }

        return $runtime;
    }

    /**
     * @return array{
     *   scope: \Closure,
     *   get: \Closure,
     *   dispatch: \Closure,
     *   api: string
     * }
     */
    private static function runtimeCalls(object $runtime): array
    {
        $scope = new ReflectionMethod($runtime, 'withinScope')->getClosure($runtime);
        $get = new ReflectionMethod($runtime, 'get')->getClosure($runtime);

        $dispatchName = 'call';
        if (method_exists($runtime, 'invoke')) {
            $candidate = new ReflectionMethod($runtime, 'invoke');
            if ($candidate->isPublic()) {
                $dispatchName = 'invoke';
            }
        }
        $dispatch = new ReflectionMethod($runtime, $dispatchName)->getClosure($runtime);

        if (!$scope instanceof \Closure || !$get instanceof \Closure || !$dispatch instanceof \Closure) {
            throw new RuntimeException('Unable to bind runtime acceptance adapters.');
        }

        return [
            'scope' => $scope,
            'get' => $get,
            'dispatch' => $dispatch,
            'api' => $dispatchName === 'invoke' ? '11.0' : '10.1.1',
        ];
    }

    private static function usageSeconds(array $usage): float
    {
        return (($usage['ru_utime.tv_sec'] ?? 0) + ($usage['ru_stime.tv_sec'] ?? 0))
            + (($usage['ru_utime.tv_usec'] ?? 0) + ($usage['ru_stime.tv_usec'] ?? 0)) / 1_000_000;
    }

    /**
     * @param array{scope: \Closure, get: \Closure, dispatch: \Closure, api: string} $calls
     */
    private static function warm(array $calls, int $concurrency): void
    {
        $sequence = 0;
        $batches = max(1, intdiv(self::WARMUP_REQUESTS + $concurrency - 1, $concurrency));
        for ($batch = 0; $batch < $batches; ++$batch) {
            $result = self::runBatch($calls, $concurrency, $sequence);
            if ($result['unexpected'] !== 0 || $result['wrong'] !== 0) {
                throw new RuntimeException('Host acceptance warmup failed correctness validation.');
            }
        }

        gc_collect_cycles();
        if (function_exists('memory_reset_peak_usage')) {
            memory_reset_peak_usage();
        }
    }
}

if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    HostAcceptance::main($argv);
}
