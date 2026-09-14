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
