<?php

declare(strict_types=1);

use Infocyph\InterMix\DI\Container;
use Infocyph\InterMix\DI\ContainerBuilder;
use Infocyph\InterMix\DI\Internal\ContainerAccess;
use Infocyph\InterMix\DI\ProductionContainer;
use Infocyph\InterMix\DI\Support\LifetimeEnum;

final class StructuredStressScopedLeaf {}

function structuredStressArtifactPath(): string
{
    return sys_get_temp_dir() . '/intermix-structured-stress-' . bin2hex(random_bytes(8)) . '.php';
}

function removeStructuredStressArtifact(string $path): void
{
    foreach ([$path, $path . '.meta.json'] as $artifact) {
        if (is_file($artifact)) {
            unlink($artifact);
        }
    }
}

/**
 * @param Container|ProductionContainer $container
 */
function exercisePersistentAttachedScopeChurn(object $container, int $iterations): void
{
    $previous = null;

    for ($iteration = 0; $iteration < $iterations; ++$iteration) {
        $seed = 'request-' . $iteration;
        testEnterScope($container, 'request', ['request.seed' => $seed]);
        $parent = $container->get('leaf');

        if ($previous !== null) {
            expect($parent)->not->toBe($previous);
        }

        $context = $container->captureScopeContext();
        $fiber = new Fiber(static fn(): array => $container->withinScopeContext(
            $context,
            static function (Container|ProductionContainer $active) use ($seed): array {
                $shared = $active->get('leaf');
                $resolvedSeed = $active->get('request.seed');
                testEnterScope($active, 'child');

                try {
                    $child = $active->get('leaf');
                } finally {
                    testLeaveScope($active);
                }

                return [$shared, $child, $resolvedSeed];
            },
        ));
        $fiber->start();
        [$shared, $child, $resolvedSeed] = $fiber->getReturn();

        expect($shared)->toBe($parent)
            ->and($child)->not->toBe($parent)
            ->and($resolvedSeed)->toBe($seed);

        testLeaveScope($container);
        $container->resetCurrentExecutionScope();
        $previous = $parent;
    }
}

function exerciseMeasuredStructuredScopeChurn(Container $container, int $iterations): void
{
    for ($iteration = 0; $iteration < $iterations; ++$iteration) {
        testEnterScope($container, 'request', ['request.seed' => $iteration]);
        $context = $container->captureScopeContext();
        $fiber = new Fiber(static fn(): object => $container->withinScopeContext(
            $context,
            static fn(Container $active): object => $active->get('leaf'),
        ));
        $fiber->start();
        $resolved = $fiber->getReturn();
        if (!$resolved instanceof StructuredStressScopedLeaf) {
            throw new RuntimeException('Persistent churn did not resolve the expected scoped service.');
        }

        testLeaveScope($container);
        $container->resetCurrentExecutionScope();
        unset($resolved, $fiber, $context);
    }
}

function structuredStressExecutionStore(Container $container): mixed
{
    $repository = ContainerAccess::repository($container);
    $property = new ReflectionProperty($repository, 'executionScopes');

    return $property->getValue($repository);
}

it('reuses one dynamic container across persistent attached request churn without leaking scope state', function (): void {
    $container = ContainerBuilder::create(uniqid('structured_stress_dynamic_'))
        ->input('request.seed')
        ->autowire('leaf', StructuredStressScopedLeaf::class, lifetime: LifetimeEnum::Scoped)
        ->build();

    exercisePersistentAttachedScopeChurn($container, 64);

    testEnterScope($container, 'request', ['request.seed' => 'final']);
    expect($container->get('request.seed'))->toBe('final');
    testLeaveScope($container);
});

it('reuses one frozen production runtime across persistent attached request churn', function (): void {
    $builder = ContainerBuilder::create(uniqid('structured_stress_production_'));
    $builder->input('request.seed')
        ->autowire('leaf', StructuredStressScopedLeaf::class, lifetime: LifetimeEnum::Scoped);

    $path = structuredStressArtifactPath();
    try {
        $builder->compile($path);
        $runtime = $builder->production($path);

        exercisePersistentAttachedScopeChurn($runtime, 64);
    } finally {
        removeStructuredStressArtifact($path);
    }
});

it('cleans nested attached frames after repeated child exceptions', function (): void {
    $nestedLeaves = 0;
    $container = ContainerBuilder::create(uniqid('structured_stress_exception_'))
        ->input('request.seed')
        ->autowire('leaf', StructuredStressScopedLeaf::class, lifetime: LifetimeEnum::Scoped)
        ->onScopeLeave('nested', static function () use (&$nestedLeaves): void {
            ++$nestedLeaves;
        })
        ->build();

    for ($iteration = 0; $iteration < 32; ++$iteration) {
        testEnterScope($container, 'request');
        $parent = $container->get('leaf');
        $context = $container->captureScopeContext();
        $fiber = new Fiber(static fn() => $container->withinScopeContext(
            $context,
            static function (Container $active): never {
                testEnterScope($active, 'nested');
                $active->get('leaf');
                throw new RuntimeException('expected-child-failure');
            },
        ));

        expect(fn() => $fiber->start())->toThrow(RuntimeException::class, 'expected-child-failure')
            ->and($container->get('leaf'))->toBe($parent);

        testLeaveScope($container);
    }

    expect($nestedLeaves)->toBe(32);
});

it('stabilizes memory and releases carrier and logical scope bookkeeping after persistent churn', function (): void {
    $container = ContainerBuilder::create(uniqid('structured_stress_memory_'))
        ->input('request.seed')
        ->autowire('leaf', StructuredStressScopedLeaf::class, lifetime: LifetimeEnum::Scoped)
        ->build();

    exerciseMeasuredStructuredScopeChurn($container, 64);
    gc_collect_cycles();
    $baseline = memory_get_usage();
    $samples = [];

    for ($window = 0; $window < 4; ++$window) {
        exerciseMeasuredStructuredScopeChurn($container, 128);
        gc_collect_cycles();
        $samples[] = memory_get_usage();
        expect(structuredStressExecutionStore($container))->toBeNull();
    }

    $growth = max(0, $samples[array_key_last($samples)] - $baseline);
    $spread = max($samples) - min($samples);
    expect($growth <= 1024 * 1024)->toBeTrue()
        ->and($spread <= 1024 * 1024)->toBeTrue();

    testEnterScope($container, 'request');
    $context = $container->captureScopeContext();
    $scopeProperty = new ReflectionProperty($context, 'scope');
    $logicalScope = $scopeProperty->getValue($context);
    $contextReference = WeakReference::create($context);
    $scopeReference = WeakReference::create($logicalScope);
    testLeaveScope($container);
    unset($logicalScope, $context);
    gc_collect_cycles();

    expect($contextReference->get())->toBeNull()
        ->and($scopeReference->get())->toBeNull()
        ->and(structuredStressExecutionStore($container))->toBeNull();
});

it('does not retain a process-global container alias registry', function (): void {
    $reflection = new ReflectionClass(Container::class);
    $first = ContainerBuilder::create(uniqid('structured_stress_owner_'))
        ->value('identity', new stdClass())
        ->build();
    $second = ContainerBuilder::create(uniqid('structured_stress_owner_'))
        ->value('identity', new stdClass())
        ->build();

    expect($reflection->hasProperty('instances'))->toBeFalse()
        ->and($first)->not->toBe($second)
        ->and($first->get('identity'))->not->toBe($second->get('identity'));
});
