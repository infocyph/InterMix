<?php

declare(strict_types=1);

namespace Infocyph\InterMix\Benchmarks;

use Fiber;
use Infocyph\InterMix\DI\Container;
use Infocyph\InterMix\DI\ContainerBuilder;
use Infocyph\InterMix\DI\ProductionContainer;
use RuntimeException;

final class ReleaseRegressionLeaf {}

final class ReleaseRegression
{
    private const int FIBER_ITERATIONS = 5000;

    private const int SAMPLES = 7;

    private const int SEQUENTIAL_ITERATIONS = 250000;

    /** @param array<int, string> $arguments */
    public static function main(array $arguments): void
    {
        $baseline = self::option($arguments, 'compare-baseline');
        $current = self::option($arguments, 'compare-current');
        if ($baseline !== null || $current !== null) {
            if ($baseline === null || $current === null) {
                throw new RuntimeException('Both --compare-baseline and --compare-current are required.');
            }

            self::compare(
                $baseline,
                $current,
                self::floatOption($arguments, 'max-sequential', 3.0),
                self::floatOption($arguments, 'max-fiber', 5.0),
            );

            return;
        }

        $autoload = self::option($arguments, 'autoload');
        $output = self::option($arguments, 'output');
        if ($autoload === null || $output === null) {
            throw new RuntimeException('--autoload and --output are required for measurement mode.');
        }
        if (!is_file($autoload)) {
            throw new RuntimeException("Autoload file is not readable: {$autoload}");
        }

        require $autoload;

        $result = self::measure();
        $encoded = json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
        if (file_put_contents($output, $encoded) === false) {
            throw new RuntimeException("Unable to write benchmark output: {$output}");
        }

        fwrite(STDOUT, $encoded);
    }

    /** @return array{php: string, sequential_production_ns: float, fiber_isolated_ns: float, samples: int} */
    private static function aggregateResults(string $paths): array
    {
        $pathList = array_values(array_filter(array_map('trim', explode(',', $paths))));
        if ($pathList === []) {
            throw new RuntimeException('At least one benchmark result is required.');
        }

        $php = null;
        $sequential = [];
        $fiber = [];
        foreach ($pathList as $path) {
            $result = self::readResult($path);
            $php ??= $result['php'];
            if ($result['php'] !== $php) {
                throw new RuntimeException('Aggregated benchmark results must use the same PHP version.');
            }
            $sequential[] = $result['sequential_production_ns'];
            $fiber[] = $result['fiber_isolated_ns'];
        }

        return [
            'php' => $php,
            'sequential_production_ns' => self::median($sequential),
            'fiber_isolated_ns' => self::median($fiber),
            'samples' => count($pathList),
        ];
    }

    private static function compare(
        string $baselinePaths,
        string $currentPaths,
        float $maxSequential,
        float $maxFiber,
    ): void {
        $baseline = self::aggregateResults($baselinePaths);
        $current = self::aggregateResults($currentPaths);
        if ($baseline['php'] !== $current['php']) {
            throw new RuntimeException('Baseline and current results must use the same PHP version.');
        }

        $sequential = self::regression(
            $baseline['sequential_production_ns'],
            $current['sequential_production_ns'],
        );
        $fiber = self::regression($baseline['fiber_isolated_ns'], $current['fiber_isolated_ns']);

        printf(
            "PHP %s release regression comparison (%d paired process samples)\nsequential production: %.3f ns -> %.3f ns (%+.2f%%, limit %.2f%%)\nisolated Fiber: %.3f ns -> %.3f ns (%+.2f%%, limit %.2f%%)\n",
            $current['php'],
            min($baseline['samples'], $current['samples']),
            $baseline['sequential_production_ns'],
            $current['sequential_production_ns'],
            $sequential,
            $maxSequential,
            $baseline['fiber_isolated_ns'],
            $current['fiber_isolated_ns'],
            $fiber,
            $maxFiber,
        );

        if ($sequential > $maxSequential || $fiber > $maxFiber) {
            throw new RuntimeException('Release performance regression budget exceeded.');
        }
    }

    /** @param array<int, string> $arguments */
    private static function floatOption(array $arguments, string $name, float $default): float
    {
        $value = self::option($arguments, $name);
        if ($value === null) {
            return $default;
        }
        if (!is_numeric($value)) {
            throw new RuntimeException("Option --{$name} must be numeric.");
        }

        return (float) $value;
    }

    /** @return array{php: string, sequential_production_ns: float, fiber_isolated_ns: float} */
    private static function measure(): array
    {
        $artifact = sys_get_temp_dir() . '/intermix-release-regression-' . bin2hex(random_bytes(8)) . '.php';

        try {
            $builder = ContainerBuilder::create('__release_regression_' . bin2hex(random_bytes(4)));
            $builder->scoped('leaf', ReleaseRegressionLeaf::class);
            $builder->compile($artifact);
            $production = $builder->production($artifact);
            $production->enterScope('request');
            $production->get('leaf');

            $dynamic = new Container('__release_regression_fiber_' . bin2hex(random_bytes(4)));
            $dynamic->scoped('leaf', ReleaseRegressionLeaf::class);

            self::warmSequential($production);
            self::warmFiber($dynamic);

            return [
                'php' => PHP_VERSION,
                'sequential_production_ns' => self::measureSequential($production),
                'fiber_isolated_ns' => self::measureFiber($dynamic),
            ];
        } finally {
            foreach ([$artifact, $artifact . '.meta.json'] as $path) {
                if (is_file($path)) {
                    unlink($path);
                }
            }
        }
    }

    private static function measureFiber(Container $container): float
    {
        $samples = [];
        $sink = null;
        for ($sample = 0; $sample < self::SAMPLES; ++$sample) {
            $started = hrtime(true);
            for ($iteration = 0; $iteration < self::FIBER_ITERATIONS; ++$iteration) {
                $fiber = new Fiber(static function () use ($container): object {
                    $container->enterScope('request');

                    try {
                        return $container->get('leaf');
                    } finally {
                        $container->leaveScope();
                    }
                });
                $fiber->start();
                $sink = $fiber->getReturn();
            }
            $samples[] = (hrtime(true) - $started) / self::FIBER_ITERATIONS;
        }
        if (!$sink instanceof ReleaseRegressionLeaf) {
            throw new RuntimeException('Fiber benchmark did not resolve the expected scoped service.');
        }

        return self::median($samples);
    }

    private static function measureSequential(ProductionContainer $container): float
    {
        $samples = [];
        $sink = null;
        for ($sample = 0; $sample < self::SAMPLES; ++$sample) {
            $started = hrtime(true);
            for ($iteration = 0; $iteration < self::SEQUENTIAL_ITERATIONS; ++$iteration) {
                $sink = $container->get('leaf');
            }
            $samples[] = (hrtime(true) - $started) / self::SEQUENTIAL_ITERATIONS;
        }
        if (!$sink instanceof ReleaseRegressionLeaf) {
            throw new RuntimeException('Sequential benchmark did not resolve the expected scoped service.');
        }

        return self::median($samples);
    }

    /** @param list<float> $values */
    private static function median(array $values): float
    {
        sort($values, SORT_NUMERIC);

        return $values[intdiv(count($values), 2)];
    }

    /** @param array<int, string> $arguments */
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

    /** @return array{php: string, sequential_production_ns: float, fiber_isolated_ns: float} */
    private static function readResult(string $path): array
    {
        $contents = file_get_contents($path);
        if (!is_string($contents)) {
            throw new RuntimeException("Unable to read benchmark result: {$path}");
        }
        $decoded = json_decode($contents, true, flags: JSON_THROW_ON_ERROR);
        if (!is_array($decoded)
            || !isset($decoded['php'], $decoded['sequential_production_ns'], $decoded['fiber_isolated_ns'])
            || !is_string($decoded['php'])
            || !is_numeric($decoded['sequential_production_ns'])
            || !is_numeric($decoded['fiber_isolated_ns'])
        ) {
            throw new RuntimeException("Invalid benchmark result: {$path}");
        }

        return [
            'php' => $decoded['php'],
            'sequential_production_ns' => (float) $decoded['sequential_production_ns'],
            'fiber_isolated_ns' => (float) $decoded['fiber_isolated_ns'],
        ];
    }

    private static function regression(float $baseline, float $current): float
    {
        if ($baseline <= 0.0) {
            throw new RuntimeException('Benchmark baseline must be greater than zero.');
        }

        return (($current / $baseline) - 1.0) * 100.0;
    }

    private static function warmFiber(Container $container): void
    {
        for ($iteration = 0; $iteration < 250; ++$iteration) {
            $fiber = new Fiber(static function () use ($container): void {
                $container->enterScope('request');

                try {
                    $container->get('leaf');
                } finally {
                    $container->leaveScope();
                }
            });
            $fiber->start();
        }
    }

    private static function warmSequential(ProductionContainer $container): void
    {
        for ($iteration = 0; $iteration < 50000; ++$iteration) {
            $container->get('leaf');
        }
    }
}

if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    ReleaseRegression::main($argv);
}
