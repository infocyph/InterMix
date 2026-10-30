<?php

declare(strict_types=1);

use Infocyph\CacheLayer\Integration\Runwire\RunwireIntegration as CacheLayerRunwireIntegration;
use Infocyph\InterMix\DI\Build\StaticRuntimeGenerator;
use Infocyph\InterMix\DI\ContainerBuilder;
use Infocyph\InterMix\DI\RuntimeContainerInterface;
use Infocyph\InterMix\DI\Support\LifetimeEnum;
use Infocyph\InterMix\Integration\Runwire\RunwireIntegration;
use Infocyph\Runwire\Coroutine\CoroutineRuntime;
use Infocyph\Runwire\Coroutine\CoroutineScope;
use Infocyph\Runwire\Exception\CancelledException;
use Infocyph\Runwire\RequestContext;
use Infocyph\Runwire\Runtime\Enum\CancellationReason;
use Infocyph\Runwire\Runtime\RequestExecutionPolicy;
use Infocyph\Runwire\Runtime\Enum\RuntimeDriver;
use Infocyph\Runwire\RuntimeCapabilities;
use Infocyph\Runwire\RuntimeContext;
use LogicException;

final class RunwireBridgeScopedLeaf {}

beforeEach(function (): void {
    $this->runwireArtifacts = [];
});

afterEach(function (): void {
    foreach ($this->runwireArtifacts as $path) {
        foreach (glob($path . '.builds/*/*') ?: [] as $file) {
            unlink($file);
        }
        foreach (glob($path . '.builds/*') ?: [] as $directory) {
            rmdir($directory);
        }
        rmdir($path . '.builds');
        unlink($path);
    }
});

function runwireBridgeContext(): RuntimeContext
{
    return RuntimeContext::fromCapabilities(
        new RuntimeCapabilities(
            driver: RuntimeDriver::NATIVE,
            persistentApplication: true,
            runwireLoopAvailable: true,
            supportsRunwireCoroutines: true,
        ),
        'intermix-test',
        concurrent: true,
    );
}

function runwireBridgeContainer(string $loader = 'dynamic'): RuntimeContainerInterface
{
    $builder = ContainerBuilder::create(uniqid('runwire_bridge_', true));
    RunwireIntegration::registerInputs($builder);

    $builder->autowire(
            'leaf',
            RunwireBridgeScopedLeaf::class,
            lifetime: LifetimeEnum::Scoped,
        );
    if ($loader === 'dynamic') {
        return $builder->build();
    }

    $path = sys_get_temp_dir() . '/intermix-runwire-bridge-' . bin2hex(random_bytes(8)) . '.php';
    test()->runwireArtifacts = [...test()->runwireArtifacts, $path];
    $report = $builder->compile($path);
    expect($report['skipped'])->toBe([]);

    return $loader === 'compiled'
        ? ContainerBuilder::loadProductionArtifact($path, $report['graph'])
        : new StaticRuntimeGenerator()->loadPrevalidated($path, $report['digest']);
}

it('shares Runwire identities and one logical DI scope through child work', function (string $loader): void {
    $runtime = runwireBridgeContext();
    $request = RequestContext::create($runtime);
    $container = runwireBridgeContainer($loader);
    $bridge = new RunwireIntegration($container, ownsCacheLayerLifecycle: true);
    $bridge->bind($runtime);

    try {
        $result = new CoroutineRuntime()->runRequest(
            $request,
            static function (CoroutineScope $scope) use ($bridge, $request): array {
                return $bridge->withinRequest(
                    $request,
                    $scope,
                    static function (RuntimeContainerInterface $active) use (
                        $bridge,
                        $request,
                        $scope,
                    ): array {
                        $parent = $active->get('leaf');
                        $cacheContext = CacheLayerRunwireIntegration::current();

                        $child = $scope->spawn(
                            $bridge->wrapChild(
                                $request,
                                $scope,
                                static fn(RuntimeContainerInterface $childRuntime): object =>
                                    $childRuntime->get('leaf'),
                            ),
                        );

                        return [
                            $parent,
                            $child->await(),
                            $active->get(RuntimeContext::class),
                            $active->get(RequestContext::class),
                            $active->get(CoroutineScope::class),
                            $cacheContext?->runtime,
                            $cacheContext?->request,
                            $cacheContext?->scope,
                        ];
                    },
                );
            },
        );

        expect($result[1])->toBe($result[0])
            ->and($result[2])->toBe($runtime)
            ->and($result[3])->toBe($request)
            ->and($result[5])->toBe($runtime)
            ->and($result[6])->toBe($request)
            ->and($result[7])->toBe($result[4])
            ->and(CacheLayerRunwireIntegration::current())->toBeNull();
    } finally {
        $bridge->release($runtime);
        $request->complete();
    }

    expect(CacheLayerRunwireIntegration::runtime())->toBeNull();
})->with(['dynamic', 'compiled', 'prevalidated']);

it('keeps downstream CacheLayer lifecycle with the designated owner', function (string $loader): void {
    $runtime = runwireBridgeContext();
    CacheLayerRunwireIntegration::bind($runtime);
    $bridge = new RunwireIntegration(runwireBridgeContainer($loader));
    $bridge->bind($runtime);

    try {
        $bridge->release($runtime);

        expect(CacheLayerRunwireIntegration::runtime())->toBe($runtime);
    } finally {
        CacheLayerRunwireIntegration::release($runtime);
    }
})->with(['dynamic', 'compiled', 'prevalidated']);

it('rejects mismatched and completed Runwire request contexts', function (string $loader): void {
    $runtime = runwireBridgeContext();
    $other = runwireBridgeContext();
    $bridge = new RunwireIntegration(runwireBridgeContainer($loader));
    $bridge->bind($runtime);

    try {
        $mismatched = RequestContext::create($other);
        expect(fn(): mixed => $bridge->withinRequest(
            $mismatched,
            null,
            static fn(RuntimeContainerInterface $active): mixed => $active,
        ))->toThrow(LogicException::class, 'different runtime');

        $completed = RequestContext::create($runtime);
        $completed->complete();

        expect(fn(): mixed => $bridge->withinRequest(
            $completed,
            null,
            static fn(RuntimeContainerInterface $active): mixed => $active,
        ))->toThrow(LogicException::class, 'Completed Runwire request');
    } finally {
        $bridge->release($runtime);
    }
})->with(['dynamic', 'compiled', 'prevalidated']);

it('rejects a coroutine scope when the bound runtime lacks Runwire coroutine capability', function (string $loader): void {
    $runtime = RuntimeContext::standalone();
    $request = RequestContext::create($runtime);
    $bridge = new RunwireIntegration(runwireBridgeContainer($loader));
    $bridge->bind($runtime);

    try {
        expect(fn(): mixed => new CoroutineRuntime()->run(
            static fn(CoroutineScope $scope): mixed => $bridge->withinRequest(
                $request,
                $scope,
                static fn(RuntimeContainerInterface $active): mixed => $active,
            ),
        ))->toThrow(LogicException::class, 'RUNWIRE_COROUTINES');
    } finally {
        $bridge->release($runtime);
        $request->complete();
    }
})->with(['dynamic', 'compiled', 'prevalidated']);

it('rejects a downstream CacheLayer binding owned by another Runwire runtime', function (string $loader): void {
    $runtime = runwireBridgeContext();
    $other = runwireBridgeContext();
    CacheLayerRunwireIntegration::bind($other);
    $bridge = new RunwireIntegration(runwireBridgeContainer($loader), ownsCacheLayerLifecycle: true);

    try {
        expect(fn() => $bridge->bind($runtime))
            ->toThrow(LogicException::class, 'CacheLayer is bound to a different Runwire runtime');
    } finally {
        CacheLayerRunwireIntegration::release($other);
    }
})->with(['dynamic', 'compiled', 'prevalidated']);


it('allows identical Runwire binding and rejects a different live runtime', function (string $loader): void {
    $runtime = runwireBridgeContext();
    $other = runwireBridgeContext();
    $bridge = new RunwireIntegration(runwireBridgeContainer($loader));

    $bridge->bind($runtime);
    $bridge->bind($runtime);

    try {
        expect($bridge->runtime())->toBe($runtime)
            ->and(fn() => $bridge->bind($other))
            ->toThrow(LogicException::class, 'already bound to a different Runwire runtime');
    } finally {
        $bridge->release($runtime);
    }
})->with(['dynamic', 'compiled', 'prevalidated']);

it('rejects cancelled request entry before invoking the callback', function (string $loader): void {
    $runtime = runwireBridgeContext();
    $request = RequestContext::create($runtime);
    $request->cancel(CancellationReason::HOST_CANCELLED);
    $bridge = new RunwireIntegration(runwireBridgeContainer($loader));
    $bridge->bind($runtime);
    $entered = false;

    try {
        expect(fn(): mixed => $bridge->withinRequest(
            $request,
            null,
            static function () use (&$entered): void {
                $entered = true;
            },
        ))->toThrow(CancelledException::class);
        expect($entered)->toBeFalse();

        expect(fn() => $bridge->checkpoint(request: $request))->toThrow(CancelledException::class);
    } finally {
        $bridge->release($runtime);
        $request->complete();
    }
})->with(['dynamic', 'compiled', 'prevalidated']);

it('rejects already-expired request deadlines without executing user work', function (string $loader): void {
    $runtime = runwireBridgeContext();
    $request = RequestContext::create(
        $runtime,
        new RequestExecutionPolicy(maxExecutionSeconds: 0.001),
        startNanoseconds: 1,
    );
    $bridge = new RunwireIntegration(runwireBridgeContainer($loader));
    $bridge->bind($runtime);
    $entered = false;

    try {
        expect(fn(): mixed => $bridge->withinRequest(
            $request,
            null,
            static function () use (&$entered): void {
                $entered = true;
            },
        ))->toThrow(CancelledException::class);
        expect($request->cancellation->reason())->toBe(CancellationReason::DEADLINE_EXCEEDED)
            ->and($entered)->toBeFalse();
    } finally {
        $bridge->release($runtime);
        $request->complete();
    }
})->with(['dynamic', 'compiled', 'prevalidated']);

it('does not release a compatible downstream CacheLayer binding it did not acquire', function (string $loader): void {
    $runtime = runwireBridgeContext();
    CacheLayerRunwireIntegration::bind($runtime);
    $bridge = new RunwireIntegration(
        runwireBridgeContainer($loader),
        ownsCacheLayerLifecycle: true,
    );

    try {
        $bridge->bind($runtime);
        $bridge->release($runtime);

        expect(CacheLayerRunwireIntegration::runtime())->toBe($runtime);
    } finally {
        CacheLayerRunwireIntegration::release($runtime);
    }
})->with(['dynamic', 'compiled', 'prevalidated']);

it('rejects cancelled request with an otherwise healthy coroutine scope', function (string $loader): void {
    $runtime = runwireBridgeContext();
    $request = RequestContext::create($runtime);
    $request->cancel(CancellationReason::HOST_CANCELLED);
    $bridge = new RunwireIntegration(runwireBridgeContainer($loader));
    $bridge->bind($runtime);
    $entered = false;

    try {
        expect(fn(): mixed => new CoroutineRuntime()->run(
            static fn(CoroutineScope $scope): mixed => $bridge->withinRequest(
                $request,
                $scope,
                static function () use (&$entered): void {
                    $entered = true;
                },
            ),
        ))->toThrow(CancelledException::class);
        expect($entered)->toBeFalse();
    } finally {
        $bridge->release($runtime);
        $request->complete();
    }
})->with(['dynamic', 'compiled', 'prevalidated']);

it('keeps the acquired CacheLayer binding alive while an InterMix boundary is active', function (string $loader): void {
    $runtime = runwireBridgeContext();
    $request = RequestContext::create($runtime);
    $bridge = new RunwireIntegration(
        runwireBridgeContainer($loader),
        ownsCacheLayerLifecycle: true,
    );
    $bridge->bind($runtime);

    try {
        $bridge->withinRequest(
            $request,
            null,
            static function () use ($bridge, $runtime): void {
                expect(fn() => $bridge->release($runtime))
                    ->toThrow(LogicException::class, 'while InterMix boundaries are active');
                expect(CacheLayerRunwireIntegration::runtime())->toBe($runtime);
            },
        );
        expect(CacheLayerRunwireIntegration::runtime())->toBe($runtime);
    } finally {
        $bridge->release($runtime);
        $request->complete();
    }

    expect(CacheLayerRunwireIntegration::runtime())->toBeNull();
})->with(['dynamic', 'compiled', 'prevalidated']);
