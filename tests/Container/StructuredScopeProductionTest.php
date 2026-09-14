<?php

declare(strict_types=1);

use Fiber;
use Infocyph\InterMix\DI\ContainerBuilder;
use Infocyph\InterMix\DI\ProductionContainer;
use Infocyph\InterMix\Exceptions\ContainerException;

final class StructuredProductionLeaf {}

final class StructuredProductionCold
{
    public static int $calls = 0;

    public static bool $suspend = false;

    public function __construct()
    {
        ++self::$calls;
        if (self::$suspend && Fiber::getCurrent() instanceof Fiber) {
            self::$suspend = false;
            Fiber::suspend();
        }
    }
}

function structuredProductionArtifactPath(): string
{
    return sys_get_temp_dir() . '/intermix-structured-' . bin2hex(random_bytes(8)) . '.php';
}

function removeStructuredProductionArtifact(string $path): void
{
    foreach ([$path, $path . '.meta.json'] as $artifact) {
        if (is_file($artifact)) {
            unlink($artifact);
        }
    }
}

/** @return array{ProductionContainer, string} */
function structuredProductionRuntime(bool $cold = false): array
{
    $builder = ContainerBuilder::create(uniqid('structured_production_'))
        ->scoped('leaf', StructuredProductionLeaf::class);
    if ($cold) {
        $builder->scoped('cold', StructuredProductionCold::class);
    }

    $path = structuredProductionArtifactPath();
    $builder->compile($path);

    return [$builder->production($path), $path];
}

it('shares a compiled logical scope while keeping sibling nested frames carrier-local', function () {
    [$runtime, $path] = structuredProductionRuntime();

    try {
        $runtime->enterScope('request');
        $parent = $runtime->get('leaf');
        $context = $runtime->captureScopeContext();

        $child = static function () use ($runtime, $context): array {
            return $runtime->withinScopeContext($context, static function (ProductionContainer $active): array {
                $active->enterScope('nested');
                $nested = $active->get('leaf');
                Fiber::suspend($nested);
                $active->leaveScope();
                $restored = $active->get('leaf');
                Fiber::suspend($restored);

                return [$nested, $restored];
            });
        };

        $fiberA = new Fiber($child);
        $fiberB = new Fiber($child);
        $nestedA = $fiberA->start();
        $nestedB = $fiberB->start();

        expect($nestedA)->not->toBe($parent)
            ->and($nestedB)->not->toBe($parent)
            ->and($nestedA)->not->toBe($nestedB)
            ->and($runtime->get('leaf'))->toBe($parent);

        expect($fiberA->resume())->toBe($parent)
            ->and($runtime->get('leaf'))->toBe($parent)
            ->and($fiberB->resume())->toBe($parent);

        $fiberA->resume();
        $fiberB->resume();

        expect($fiberA->getReturn()[1])->toBe($parent)
            ->and($fiberB->getReturn()[1])->toBe($parent);

        $runtime->leaveScope();
    } finally {
        removeStructuredProductionArtifact($path);
    }
});

it('rejects compiled owner close while an attachment is live before firing leave hooks', function () {
    $leaves = [];
    $builder = ContainerBuilder::create(uniqid('structured_production_owner_'))
        ->scoped('leaf', StructuredProductionLeaf::class)
        ->onScopeLeave('request', static function (string $scope) use (&$leaves): void {
            $leaves[] = $scope;
        });
    $path = structuredProductionArtifactPath();

    try {
        $builder->compile($path);
        $runtime = $builder->production($path);
        $runtime->enterScope('request');
        $context = $runtime->captureScopeContext();

        $child = new Fiber(static fn(): mixed => $runtime->withinScopeContext(
            $context,
            static function (ProductionContainer $active): mixed {
                $leaf = $active->get('leaf');
                Fiber::suspend();

                return $leaf;
            },
        ));
        $child->start();

        expect(fn() => $runtime->leaveScope())
            ->toThrow(ContainerException::class, 'child execution carriers are still attached');
        expect($leaves)->toBe([]);

        $child->resume();
        $runtime->leaveScope();

        expect($leaves)->toBe(['request']);
    } finally {
        removeStructuredProductionArtifact($path);
    }
});

it('guards cold compiled scoped construction across sibling carriers', function () {
    StructuredProductionCold::$calls = 0;
    StructuredProductionCold::$suspend = true;
    [$runtime, $path] = structuredProductionRuntime(true);

    try {
        $runtime->enterScope('request');
        $context = $runtime->captureScopeContext();

        $first = new Fiber(static fn(): StructuredProductionCold => $runtime->withinScopeContext(
            $context,
            static fn(ProductionContainer $active): StructuredProductionCold => $active->get('cold'),
        ));
        $first->start();
        expect($first->isSuspended())->toBeTrue();

        $second = new Fiber(static fn(): StructuredProductionCold => $runtime->withinScopeContext(
            $context,
            static fn(ProductionContainer $active): StructuredProductionCold => $active->get('cold'),
        ));
        expect(fn() => $second->start())
            ->toThrow(ContainerException::class, 'already being constructed by another execution carrier');

        $first->resume();
        $resolved = $first->getReturn();

        expect($runtime->get('cold'))->toBe($resolved)
            ->and(StructuredProductionCold::$calls)->toBe(1);

        $runtime->leaveScope();
    } finally {
        StructuredProductionCold::$suspend = false;
        removeStructuredProductionArtifact($path);
    }
});

it('resets a compiled attached carrier without closing the shared owner scope', function () {
    $leaves = [];
    $builder = ContainerBuilder::create(uniqid('structured_production_reset_'))
        ->scoped('leaf', StructuredProductionLeaf::class)
        ->onScopeLeave('request', static function (string $scope) use (&$leaves): void {
            $leaves[] = $scope;
        })
        ->onScopeLeave('nested', static function (string $scope) use (&$leaves): void {
            $leaves[] = $scope;
        });
    $path = structuredProductionArtifactPath();

    try {
        $builder->compile($path);
        $runtime = $builder->production($path);
        $runtime->enterScope('request');
        $parent = $runtime->get('leaf');
        $context = $runtime->captureScopeContext();

        $child = new Fiber(static function () use ($runtime, $context): StructuredProductionLeaf {
            $runtime->withinScopeContext($context, static function (ProductionContainer $active): void {
                $active->enterScope('nested');
                $active->get('leaf');
                $active->resetCurrentExecutionScope();
                $active->resetCurrentExecutionScope();
            });

            $runtime->enterScope('independent');
            $fresh = $runtime->get('leaf');
            $runtime->leaveScope();

            return $fresh;
        });
        $child->start();

        expect($leaves)->toBe(['nested'])
            ->and($child->getReturn())->not->toBe($parent)
            ->and($runtime->get('leaf'))->toBe($parent);

        $runtime->leaveScope();
        expect($leaves)->toBe(['nested', 'request']);
    } finally {
        removeStructuredProductionArtifact($path);
    }
});

it('rejects foreign and stale compiled scope contexts', function () {
    [$owner, $ownerPath] = structuredProductionRuntime();
    [$foreign, $foreignPath] = structuredProductionRuntime();

    try {
        $owner->enterScope('request');
        $context = $owner->captureScopeContext();

        $fiber = new Fiber(static fn(): mixed => $foreign->withinScopeContext(
            $context,
            static fn(ProductionContainer $active): mixed => $active,
        ));
        expect(fn() => $fiber->start())
            ->toThrow(ContainerException::class, 'different container');

        $owner->leaveScope();

        $stale = new Fiber(static fn(): mixed => $owner->withinScopeContext(
            $context,
            static fn(ProductionContainer $active): mixed => $active,
        ));
        expect(fn() => $stale->start())
            ->toThrow(ContainerException::class, 'no longer active');
    } finally {
        removeStructuredProductionArtifact($ownerPath);
        removeStructuredProductionArtifact($foreignPath);
    }
});
