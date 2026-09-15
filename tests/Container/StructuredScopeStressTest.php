<?php

declare(strict_types=1);

use Infocyph\InterMix\DI\Container;
use Infocyph\InterMix\DI\ContainerBuilder;
use Infocyph\InterMix\DI\ProductionContainer;

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
        $container->enterScope('request', ['request.seed' => $seed]);
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
                $active->enterScope('child');

                try {
                    $child = $active->get('leaf');
                } finally {
                    $active->leaveScope();
                }

                return [$shared, $child, $resolvedSeed];
            },
        ));
        $fiber->start();
        [$shared, $child, $resolvedSeed] = $fiber->getReturn();

        expect($shared)->toBe($parent)
            ->and($child)->not->toBe($parent)
            ->and($resolvedSeed)->toBe($seed);

        $container->leaveScope();
        $container->resetCurrentExecutionScope();
        $previous = $parent;
    }
}

function exerciseMeasuredStructuredScopeChurn(Container $container, int $iterations): void
{
    for ($iteration = 0; $iteration < $iterations; ++$iteration) {
        $container->enterScope('request', ['request.seed' => $iteration]);
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

        $container->leaveScope();
        $container->resetCurrentExecutionScope();
        unset($resolved, $fiber, $context);
    }
}

function structuredStressExecutionStore(Container $container): mixed
{
    $repository = $container->getRepository();
    $property = new ReflectionProperty($repository, 'executionScopes');

    return $property->getValue($repository);
}

/** @return array<string, Container> */
function structuredStressContainerAliases(): array
{
    $property = new ReflectionProperty(Container::class, 'instances');
    $aliases = $property->getValue();
    if (!is_array($aliases)) {
        throw new RuntimeException('Container alias registry must be an array.');
    }

    return $aliases;
}

it('reuses one dynamic container across persistent attached request churn without leaking scope state', function (): void {
    $container = new Container(uniqid('structured_stress_dynamic_'));
    $container->scoped('leaf', StructuredStressScopedLeaf::class);

    exercisePersistentAttachedScopeChurn($container, 64);

    $container->enterScope('request', ['request.seed' => 'final']);
    expect($container->get('request.seed'))->toBe('final');
    $container->leaveScope();
});

it('reuses compiled and deoptimized runtimes across persistent attached request churn', function (): void {
    $builder = ContainerBuilder::create(uniqid('structured_stress_production_'));
    $builder->scoped('leaf', StructuredStressScopedLeaf::class);

    $path = structuredStressArtifactPath();
    try {
        $builder->compile($path);
        $runtime = $builder->production($path);

        exercisePersistentAttachedScopeChurn($runtime, 32);

        $runtime->deoptimize();
        exercisePersistentAttachedScopeChurn($runtime, 32);
    } finally {
        removeStructuredStressArtifact($path);
    }
});

it('cleans nested attached frames after repeated child exceptions', function (): void {
    $container = new Container(uniqid('structured_stress_exception_'));
    $container->scoped('leaf', StructuredStressScopedLeaf::class);
    $nestedLeaves = 0;
    $container->onScopeLeave('nested', static function () use (&$nestedLeaves): void {
        ++$nestedLeaves;
    });

    for ($iteration = 0; $iteration < 32; ++$iteration) {
        $container->enterScope('request');
        $parent = $container->get('leaf');
        $context = $container->captureScopeContext();
        $fiber = new Fiber(static fn() => $container->withinScopeContext(
            $context,
            static function (Container $active): never {
                $active->enterScope('nested');
                $active->get('leaf');
                throw new RuntimeException('expected-child-failure');
            },
        ));

        expect(fn() => $fiber->start())->toThrow(RuntimeException::class, 'expected-child-failure')
            ->and($container->get('leaf'))->toBe($parent);

        $container->leaveScope();
    }

    expect($nestedLeaves)->toBe(32);
});

it('stabilizes memory and releases carrier and logical scope bookkeeping after persistent churn', function (): void {
    $container = new Container(uniqid('structured_stress_memory_'));
    $container->scoped('leaf', StructuredStressScopedLeaf::class);

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

    $container->enterScope('request');
    $context = $container->captureScopeContext();
    $scopeProperty = new ReflectionProperty($context, 'scope');
    $logicalScope = $scopeProperty->getValue($context);
    $contextReference = WeakReference::create($context);
    $scopeReference = WeakReference::create($logicalScope);
    $container->leaveScope();
    unset($logicalScope, $context);
    gc_collect_cycles();

    expect($contextReference->get())->toBeNull()
        ->and($scopeReference->get())->toBeNull()
        ->and(structuredStressExecutionStore($container))->toBeNull();
});

it('keeps the application container alias registry cardinality stable across framework-style request reuse', function (): void {
    $before = structuredStressContainerAliases();
    $alias = '__structured_stress_application_' . bin2hex(random_bytes(8));
    $container = Container::instance($alias);

    try {
        for ($iteration = 0; $iteration < 256; ++$iteration) {
            expect(Container::instance($alias))->toBe($container);
            $container->withinScope('request', static fn(): bool => true);
        }

        $during = structuredStressContainerAliases();
        expect(count($during))->toBe(count($before) + 1)
            ->and($during[$alias] ?? null)->toBe($container);
    } finally {
        $container->unset();
    }

    $after = structuredStressContainerAliases();
    expect(count($after))->toBe(count($before))
        ->and(array_key_exists($alias, $after))->toBeFalse();
});
