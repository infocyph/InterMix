<?php

declare(strict_types=1);

use Infocyph\InterMix\DI\Container;
use Infocyph\InterMix\DI\ContainerBuilder;
use Infocyph\InterMix\DI\ProductionContainer;
use Infocyph\InterMix\DI\Support\LifetimeEnum;
use Infocyph\InterMix\Exceptions\ContainerException;

final class StructuredParityScopedLeaf {}

function structuredParityArtifactPath(): string
{
    return sys_get_temp_dir() . '/intermix-structured-parity-' . bin2hex(random_bytes(8)) . '.php';
}

function removeStructuredParityArtifact(string $path): void
{
    foreach ([$path, $path . '.meta.json'] as $artifact) {
        if (is_file($artifact)) {
            unlink($artifact);
        }
    }
}

/** @param Container|ProductionContainer $container */
function exerciseStructuredScopeParity(object $container): void
{
    $container->enterScope('request', ['request.seed' => 'seeded']);
    $parentLeaf = $container->get('leaf');
    $parentIsland = $container->get('island');
    $context = $container->captureScopeContext();

    $spawn = static function () use ($container, $context): Fiber {
        return new Fiber(static fn(): array => $container->withinScopeContext(
            $context,
            static function (Container|ProductionContainer $active): array {
                $sharedLeaf = $active->get('leaf');
                $sharedIsland = $active->get('island');
                $seed = $active->get('request.seed');
                $active->enterScope('nested');
                $nestedLeaf = $active->get('leaf');
                $nestedIsland = $active->get('island');
                Fiber::suspend('nested-ready');
                $active->leaveScope();

                return [
                    $sharedLeaf,
                    $sharedIsland,
                    $nestedLeaf,
                    $nestedIsland,
                    $active->get('leaf'),
                    $active->get('island'),
                    $seed,
                ];
            },
        ));
    };

    $left = $spawn();
    $right = $spawn();
    expect($left->start())->toBe('nested-ready')
        ->and($right->start())->toBe('nested-ready');
    $left->resume();
    $right->resume();
    $leftResult = $left->getReturn();
    $rightResult = $right->getReturn();

    foreach ([$leftResult, $rightResult] as $result) {
        expect($result[0])->toBe($parentLeaf)
            ->and($result[1])->toBe($parentIsland)
            ->and($result[2])->not->toBe($parentLeaf)
            ->and($result[3])->not->toBe($parentIsland)
            ->and($result[4])->toBe($parentLeaf)
            ->and($result[5])->toBe($parentIsland)
            ->and($result[6])->toBe('seeded');
    }

    expect($leftResult[2])->not->toBe($rightResult[2])
        ->and($leftResult[3])->not->toBe($rightResult[3]);

    $throwing = new Fiber(static fn() => $container->withinScopeContext(
        $context,
        static function (Container|ProductionContainer $active): never {
            $active->enterScope('nested-failure');
            $active->get('leaf');
            throw new RuntimeException('parity-child-failure');
        },
    ));
    expect(fn() => $throwing->start())->toThrow(RuntimeException::class, 'parity-child-failure')
        ->and($container->get('leaf'))->toBe($parentLeaf)
        ->and($container->get('island'))->toBe($parentIsland);

    $container->leaveScope();
    $container->resetCurrentExecutionScope();
    $container->resetCurrentExecutionScope();

    $stale = new Fiber(static fn() => $container->withinScopeContext(
        $context,
        static fn(): null => null,
    ));
    expect(fn() => $stale->start())->toThrow(ContainerException::class);
}

it('keeps structured scope semantics identical in the dynamic container', function (): void {
    $container = new Container(uniqid('structured_parity_dynamic_'));
    $container->scoped('leaf', StructuredParityScopedLeaf::class)
        ->bindFactory('island', static fn(): stdClass => new stdClass(), LifetimeEnum::Scoped);

    exerciseStructuredScopeParity($container);
});

it('keeps structured scope semantics identical across compiled runtime islands', function (): void {
    $builder = ContainerBuilder::create(uniqid('structured_parity_compiled_'));
    $builder->scoped('leaf', StructuredParityScopedLeaf::class)
        ->bindFactory('island', static fn(): stdClass => new stdClass(), LifetimeEnum::Scoped);

    $path = structuredParityArtifactPath();
    try {
        $builder->compile($path);
        $runtime = $builder->production($path);
        exerciseStructuredScopeParity($runtime);
    } finally {
        removeStructuredParityArtifact($path);
    }
});

it('keeps structured scope semantics identical after explicit production deoptimization', function (): void {
    $builder = ContainerBuilder::create(uniqid('structured_parity_deoptimized_'));
    $builder->scoped('leaf', StructuredParityScopedLeaf::class)
        ->bindFactory('island', static fn(): stdClass => new stdClass(), LifetimeEnum::Scoped);

    $path = structuredParityArtifactPath();
    try {
        $builder->compile($path);
        $runtime = $builder->production($path);
        $runtime->deoptimize();
        exerciseStructuredScopeParity($runtime);
    } finally {
        removeStructuredParityArtifact($path);
    }
});
