<?php

declare(strict_types=1);

use Infocyph\CacheLayer\Integration\Runwire\RunwireIntegration as CacheLayerRunwireIntegration;
use Infocyph\InterMix\DI\ContainerBuilder;
use Infocyph\InterMix\DI\RuntimeContainerInterface;
use Infocyph\InterMix\DI\Support\LifetimeEnum;
use Infocyph\InterMix\Integration\Runwire\RunwireIntegration;
use Infocyph\Runwire\Coroutine\CoroutineRuntime;
use Infocyph\Runwire\Coroutine\CoroutineScope;
use Infocyph\Runwire\RequestContext;
use Infocyph\Runwire\Runtime\Enum\RuntimeDriver;
use Infocyph\Runwire\RuntimeCapabilities;
use Infocyph\Runwire\RuntimeContext;
use LogicException;

final class RunwireBridgeScopedLeaf {}

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

function runwireBridgeContainer(): RuntimeContainerInterface
{
    $builder = ContainerBuilder::create(uniqid('runwire_bridge_', true));
    RunwireIntegration::registerInputs($builder);

    return $builder
        ->autowire(
            'leaf',
            RunwireBridgeScopedLeaf::class,
            lifetime: LifetimeEnum::Scoped,
        )
        ->build();
}

it('shares Runwire identities and one logical DI scope through child work', function (): void {
    $runtime = runwireBridgeContext();
    $request = RequestContext::create($runtime);
    $container = runwireBridgeContainer();
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
});

it('keeps downstream CacheLayer lifecycle with the designated owner', function (): void {
    $runtime = runwireBridgeContext();
    CacheLayerRunwireIntegration::bind($runtime);
    $bridge = new RunwireIntegration(runwireBridgeContainer());
    $bridge->bind($runtime);

    try {
        $bridge->release($runtime);

        expect(CacheLayerRunwireIntegration::runtime())->toBe($runtime);
    } finally {
        CacheLayerRunwireIntegration::release($runtime);
    }
});

it('rejects mismatched and completed Runwire request contexts', function (): void {
    $runtime = runwireBridgeContext();
    $other = runwireBridgeContext();
    $bridge = new RunwireIntegration(runwireBridgeContainer());
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
});

it('rejects a coroutine scope when the bound runtime lacks Runwire coroutine capability', function (): void {
    $runtime = RuntimeContext::standalone();
    $request = RequestContext::create($runtime);
    $bridge = new RunwireIntegration(runwireBridgeContainer());
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
});

it('rejects a downstream CacheLayer binding owned by another Runwire runtime', function (): void {
    $runtime = runwireBridgeContext();
    $other = runwireBridgeContext();
    CacheLayerRunwireIntegration::bind($other);
    $bridge = new RunwireIntegration(runwireBridgeContainer(), ownsCacheLayerLifecycle: true);

    try {
        expect(fn() => $bridge->bind($runtime))
            ->toThrow(LogicException::class, 'CacheLayer is bound to a different Runwire runtime');
    } finally {
        CacheLayerRunwireIntegration::release($other);
    }
});


it('allows identical Runwire binding and rejects a different live runtime', function (): void {
    $runtime = runwireBridgeContext();
    $other = runwireBridgeContext();
    $bridge = new RunwireIntegration(runwireBridgeContainer());

    $bridge->bind($runtime);
    $bridge->bind($runtime);

    try {
        expect($bridge->runtime())->toBe($runtime)
            ->and(fn() => $bridge->bind($other))
            ->toThrow(LogicException::class, 'already bound to a different Runwire runtime');
    } finally {
        $bridge->release($runtime);
    }
});
