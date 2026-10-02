<?php

declare(strict_types=1);

use Infocyph\InterMix\Benchmarks\HostAcceptance;
use Infocyph\InterMix\DI\ContainerBuilder;
use Infocyph\InterMix\DI\Internal\ContainerAccess;
use Infocyph\InterMix\DI\ProductionContainer;
use Psr\Container\NotFoundExceptionInterface;

require_once dirname(__DIR__, 2) . '/benchmarks/HostAcceptance.php';

final class ReleaseReadinessPlannerProbe
{
    public function abcdefghijklmnop(): int
    {
        return 1;
    }

    public function unsupported(string $value = 'fallback'): string
    {
        return $value;
    }
}

/**
 * @param list<float> $samples
 */
function releaseReadinessRecordSample(
    array &$samples,
    int &$observations,
    int &$samplerState,
    float $value,
): void {
    static $record = null;

    $record ??= Closure::bind(
        static function (
            array &$boundSamples,
            int &$boundObservations,
            int &$boundState,
            float $boundValue,
        ): void {
            HostAcceptance::recordSample(
                $boundSamples,
                $boundObservations,
                $boundState,
                $boundValue,
            );
        },
        null,
        HostAcceptance::class,
    );

    $record($samples, $observations, $samplerState, $value);
}

function releaseReadinessArtifactPath(): string
{
    return sys_get_temp_dir() . '/intermix-release-readiness-' . bin2hex(random_bytes(8)) . '.php';
}

function releaseReadinessRemoveArtifact(string $path): void
{
    foreach ([$path, $path . '.meta.json'] as $artifact) {
        if (is_file($artifact)) {
            unlink($artifact);
        }
    }
}

function releaseReadinessMethodVariant(int $bits): string
{
    $base = 'abcdefghijklmnop';
    $variant = '';
    for ($offset = 0; $offset < strlen($base); ++$offset) {
        $letter = $base[$offset];
        $variant .= (($bits >> $offset) & 1) === 1 ? strtoupper($letter) : $letter;
    }

    return $variant;
}

it('keeps periodic latency tails in the bounded host reservoir', function (): void {
    $samples = [];
    $observations = 0;
    $samplerState = 104_729;

    for ($index = 0; $index < 640_000; ++$index) {
        releaseReadinessRecordSample(
            $samples,
            $observations,
            $samplerState,
            ($index % 32) === 1 ? 100.0 : 1.0,
        );
    }

    sort($samples, SORT_NUMERIC);
    $p99 = $samples[(int) floor((count($samples) - 1) * 0.99)];

    expect($observations)->toBe(640_000)
        ->and($samples)->toHaveCount(20_000)
        ->and($p99)->toBe(100.0)
        ->and(count(array_filter($samples, static fn(float $value): bool => $value === 100.0)))
        ->toBeGreaterThan(200);
});

it('keeps latency observations from early middle and late run windows', function (): void {
    $samples = [];
    $observations = 0;
    $samplerState = 130_363;

    for ($index = 0; $index < 120_000; ++$index) {
        $window = 1.0 + floor($index / 40_000);
        releaseReadinessRecordSample($samples, $observations, $samplerState, $window);
    }

    $counts = array_count_values($samples);

    expect($samples)->toHaveCount(20_000)
        ->and($counts[1] ?? 0)->toBeGreaterThan(2_000)
        ->and($counts[2] ?? 0)->toBeGreaterThan(2_000)
        ->and($counts[3] ?? 0)->toBeGreaterThan(2_000);
});

it('does not memoize high-cardinality missing definition lifetimes', function (): void {
    $runtime = ContainerBuilder::create(uniqid('missing-lifetime-', true))
        ->value('known', 42)
        ->build();

    for ($index = 0; $index < 10_000; ++$index) {
        try {
            $runtime->get('absent-' . $index);
        } catch (NotFoundExceptionInterface) {
        }
    }

    $repository = ContainerAccess::repository($runtime);
    $cacheProperty = new ReflectionProperty($repository, 'definitionLifetimeCache');

    expect($cacheProperty->getValue($repository))->toHaveCount(0);

    $runtime->resetCurrentExecutionScope();

    expect($cacheProperty->getValue($repository))->toHaveCount(0)
        ->and($runtime->get('known'))->toBe(42)
        ->and($cacheProperty->getValue($repository))->toHaveCount(1);
});

it('canonicalizes and bounds production invocation plans without retaining receivers', function (): void {
    $builder = ContainerBuilder::create(uniqid('planner-bound-', true))
        ->releaseIdentity('planner-bound');
    $path = releaseReadinessArtifactPath();

    try {
        $builder->compile($path);
        $runtime = $builder->production($path);
        $target = new ReleaseReadinessPlannerProbe();

        for ($index = 0; $index < 10_000; ++$index) {
            expect($runtime->invoke([$target, releaseReadinessMethodVariant($index)]))->toBe(1);
        }

        $plannerProperty = new ReflectionProperty(ProductionContainer::class, 'productionInvocationPlanner');
        $planner = $plannerProperty->getValue($runtime);
        expect($planner)->not->toBeNull();

        $plansProperty = new ReflectionProperty($planner, 'plans');
        $plans = $plansProperty->getValue($planner);
        expect($plans)->toHaveCount(1);

        $synthetic = [];
        for ($index = 0; $index < 512; ++$index) {
            $synthetic['synthetic-' . $index] = false;
        }
        $plansProperty->setValue($planner, $synthetic);

        expect($runtime->invoke([$target, 'abcdefghijklmnop']))->toBe(1)
            ->and($runtime->invoke([$target, 'UnSuPpOrTeD']))->toBe('fallback');

        $plans = $plansProperty->getValue($planner);
        expect($plans)->toHaveCount(512)
            ->and($plans)->not->toHaveKey('synthetic-0')
            ->and($plans)->not->toHaveKey('synthetic-1');

        foreach ($plans as $plan) {
            expect($plan === false || is_array($plan))->toBeTrue();
            if (is_array($plan)) {
                foreach ($plan as $dependency) {
                    expect($dependency)->toBeString();
                }
            }
        }
    } finally {
        releaseReadinessRemoveArtifact($path);
    }
});
