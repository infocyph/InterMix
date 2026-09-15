<?php

declare(strict_types=1);

use Fiber;
use Infocyph\InterMix\DI\Container;
use Infocyph\InterMix\DI\ContainerBuilder;

final class ExecutionContextScopedLeaf {}

/**
 * @return array{
 *   a: array{ExecutionContextScopedLeaf, ExecutionContextScopedLeaf, ExecutionContextScopedLeaf, ExecutionContextScopedLeaf},
 *   b: array{ExecutionContextScopedLeaf, ExecutionContextScopedLeaf, ExecutionContextScopedLeaf, ExecutionContextScopedLeaf}
 * }
 */
function interleaveExecutionContextScopes(object $container): array
{
    $seedA = new ExecutionContextScopedLeaf();
    $seedB = new ExecutionContextScopedLeaf();

    $fiberA = new Fiber(static function () use ($container, $seedA): array {
        $container->enterScope('request', ['seeded' => $seedA]);
        $first = $container->get('leaf');
        $seeded = $container->get('seeded');
        Fiber::suspend();
        $again = $container->get('leaf');
        $seededAgain = $container->get('seeded');
        $container->leaveScope();

        return [$first, $again, $seeded, $seededAgain];
    });

    $fiberB = new Fiber(static function () use ($container, $seedB): array {
        $container->enterScope('request', ['seeded' => $seedB]);
        $first = $container->get('leaf');
        $seeded = $container->get('seeded');
        Fiber::suspend();
        $again = $container->get('leaf');
        $seededAgain = $container->get('seeded');
        $container->leaveScope();

        return [$first, $again, $seeded, $seededAgain];
    });

    $fiberA->start();
    $fiberB->start();
    $fiberA->resume();
    $fiberB->resume();

    return [
        'a' => $fiberA->getReturn(),
        'b' => $fiberB->getReturn(),
    ];
}

/**
 * @return array{
 *   a: array{ExecutionContextScopedLeaf, ExecutionContextScopedLeaf, ExecutionContextScopedLeaf},
 *   b: array{ExecutionContextScopedLeaf, ExecutionContextScopedLeaf, ExecutionContextScopedLeaf}
 * }
 */
function interleaveNestedExecutionContextScopes(object $container): array
{
    $fiberA = new Fiber(static function () use ($container): array {
        $container->enterScope('request');
        $parent = $container->get('leaf');
        Fiber::suspend();

        $container->enterScope('nested-a');
        $nested = $container->get('leaf');
        Fiber::suspend();

        $container->leaveScope();
        $restored = $container->get('leaf');
        $container->leaveScope();

        return [$parent, $nested, $restored];
    });

    $fiberB = new Fiber(static function () use ($container): array {
        $container->enterScope('request');
        $parent = $container->get('leaf');
        Fiber::suspend();

        $container->enterScope('nested-b');
        $nested = $container->get('leaf');
        Fiber::suspend();

        $container->leaveScope();
        $restored = $container->get('leaf');
        $container->leaveScope();

        return [$parent, $nested, $restored];
    });

    $fiberA->start();
    $fiberB->start();
    $fiberA->resume();
    $fiberB->resume();
    $fiberA->resume();
    $fiberB->resume();

    return [
        'a' => $fiberA->getReturn(),
        'b' => $fiberB->getReturn(),
    ];
}

/** @return array{mixed, mixed} */
function interleaveNullableExecutionContextSeeds(object $container): array
{
    $seedB = new ExecutionContextScopedLeaf();

    $fiberA = new Fiber(static function () use ($container): mixed {
        $container->enterScope('request', ['nullable' => null]);
        $seed = $container->get('nullable');
        Fiber::suspend();
        $container->leaveScope();

        return $seed;
    });

    $fiberB = new Fiber(static function () use ($container, $seedB): mixed {
        $container->enterScope('request', ['nullable' => $seedB]);
        $seed = $container->get('nullable');
        Fiber::suspend();
        $container->leaveScope();

        return $seed;
    });

    $fiberA->start();
    $fiberB->start();
    $fiberA->resume();
    $fiberB->resume();

    return [$fiberA->getReturn(), $fiberB->getReturn()];
}

/** @return array{ExecutionContextScopedLeaf, ExecutionContextScopedLeaf} */
function executionContextThrowableCleanup(object $container): array
{
    $fiber = new Fiber(static function () use ($container): array {
        $first = null;

        try {
            $container->withinScope('request', static function (object $activeContainer) use (&$first): never {
                $first = $activeContainer->get('leaf');

                throw new RuntimeException('expected scope failure');
            });
        } catch (RuntimeException $exception) {
            if ($exception->getMessage() !== 'expected scope failure') {
                throw $exception;
            }
        }

        $container->enterScope('request');
        $second = $container->get('leaf');
        $container->leaveScope();

        return [$first, $second];
    });
    $fiber->start();

    return $fiber->getReturn();
}

/** @return array<int, ExecutionContextScopedLeaf> */
function repeatedExecutionContextScopeRoots(object $container, int $iterations = 32): array
{
    $resolved = [];
    for ($i = 0; $i < $iterations; ++$i) {
        $fiber = new Fiber(static function () use ($container): ExecutionContextScopedLeaf {
            $container->enterScope('request');
            $leaf = $container->get('leaf');
            $container->leaveScope();

            return $leaf;
        });
        $fiber->start();
        $resolved[] = $fiber->getReturn();
    }

    return $resolved;
}

function executionContextArtifactPath(): string
{
    return sys_get_temp_dir() . '/intermix-context-' . bin2hex(random_bytes(8)) . '.php';
}

function removeExecutionContextArtifact(string $path): void
{
    foreach ([$path, $path . '.meta.json'] as $artifact) {
        if (is_file($artifact)) {
            unlink($artifact);
        }
    }
}

it('isolates dynamic scoped identity and seeds across interleaved Fibers', function () {
    $container = new Container(uniqid('context_dynamic_'));
    $container->scoped('leaf', ExecutionContextScopedLeaf::class)
        ->scoped('seeded', ExecutionContextScopedLeaf::class);

    $result = interleaveExecutionContextScopes($container);

    expect($result['a'][0])->toBe($result['a'][1])
        ->and($result['b'][0])->toBe($result['b'][1])
        ->and($result['a'][0])->not->toBe($result['b'][0])
        ->and($result['a'][2])->toBe($result['a'][3])
        ->and($result['b'][2])->toBe($result['b'][3])
        ->and($result['a'][2])->not->toBe($result['b'][2]);
});

it('isolates compiled scoped identity and seeds across interleaved Fibers', function () {
    $builder = ContainerBuilder::create(uniqid('context_compiled_'))
        ->scoped('leaf', ExecutionContextScopedLeaf::class)
        ->scoped('seeded', ExecutionContextScopedLeaf::class);
    $path = executionContextArtifactPath();

    try {
        $builder->compile($path);
        $runtime = $builder->production($path);
        $result = interleaveExecutionContextScopes($runtime);

        expect($result['a'][0])->toBe($result['a'][1])
            ->and($result['b'][0])->toBe($result['b'][1])
            ->and($result['a'][0])->not->toBe($result['b'][0])
            ->and($result['a'][2])->toBe($result['a'][3])
            ->and($result['b'][2])->toBe($result['b'][3])
            ->and($result['a'][2])->not->toBe($result['b'][2]);
    } finally {
        removeExecutionContextArtifact($path);
    }
});

it('keeps sequential scope state isolated around Fiber scopes', function () {
    $container = new Container(uniqid('context_mixed_'));
    $container->scoped('leaf', ExecutionContextScopedLeaf::class)
        ->scoped('seeded', ExecutionContextScopedLeaf::class);

    $beforeSeed = new ExecutionContextScopedLeaf();
    $container->enterScope('request', ['seeded' => $beforeSeed]);
    $before = $container->get('leaf');
    $beforeAgain = $container->get('leaf');
    $beforeSeeded = $container->get('seeded');
    $container->leaveScope();

    $fibers = interleaveExecutionContextScopes($container);

    $afterSeed = new ExecutionContextScopedLeaf();
    $container->enterScope('request', ['seeded' => $afterSeed]);
    $after = $container->get('leaf');
    $afterSeeded = $container->get('seeded');
    $container->leaveScope();

    expect($before)->toBe($beforeAgain)
        ->and($beforeSeeded)->toBe($beforeSeed)
        ->and($afterSeeded)->toBe($afterSeed)
        ->and($after)->not->toBe($before)
        ->and($fibers['a'][0])->not->toBe($before)
        ->and($fibers['b'][0])->not->toBe($before)
        ->and($fibers['a'][0])->not->toBe($after)
        ->and($fibers['b'][0])->not->toBe($after);
});

it('dispatches scope leave hooks for sequential and Fiber scopes', function () {
    $container = new Container(uniqid('context_hooks_'));
    $calls = [];
    $container->onScopeLeave(
        'request',
        static function (string $scope, Container $activeContainer) use (&$calls, $container): void {
            $calls[] = [$scope, Fiber::getCurrent() instanceof Fiber, $activeContainer === $container];
        },
    );

    $container->enterScope('request');
    $container->leaveScope();

    $fiber = new Fiber(static function () use ($container): void {
        $container->enterScope('request');
        $container->leaveScope();
    });
    $fiber->start();

    expect($calls)->toBe([
        ['request', false, true],
        ['request', true, true],
    ]);
});

it('keeps nested dynamic Fiber scope stacks independent', function () {
    $container = new Container(uniqid('context_nested_dynamic_'));
    $container->scoped('leaf', ExecutionContextScopedLeaf::class);

    $result = interleaveNestedExecutionContextScopes($container);

    expect($result['a'][0])->toBe($result['a'][2])
        ->and($result['b'][0])->toBe($result['b'][2])
        ->and($result['a'][1])->not->toBe($result['a'][0])
        ->and($result['b'][1])->not->toBe($result['b'][0])
        ->and($result['a'][0])->not->toBe($result['b'][0])
        ->and($result['a'][1])->not->toBe($result['b'][1]);
});

it('keeps nested compiled Fiber scope stacks independent', function () {
    $builder = ContainerBuilder::create(uniqid('context_nested_compiled_'))
        ->scoped('leaf', ExecutionContextScopedLeaf::class);
    $path = executionContextArtifactPath();

    try {
        $builder->compile($path);
        $runtime = $builder->production($path);
        $result = interleaveNestedExecutionContextScopes($runtime);

        expect($result['a'][0])->toBe($result['a'][2])
            ->and($result['b'][0])->toBe($result['b'][2])
            ->and($result['a'][1])->not->toBe($result['a'][0])
            ->and($result['b'][1])->not->toBe($result['b'][0])
            ->and($result['a'][0])->not->toBe($result['b'][0])
            ->and($result['a'][1])->not->toBe($result['b'][1]);
    } finally {
        removeExecutionContextArtifact($path);
    }
});

it('preserves null seed isolation across dynamic Fibers', function () {
    $container = new Container(uniqid('context_null_dynamic_'));
    $container->scoped('nullable', ExecutionContextScopedLeaf::class);

    [$nullSeed, $objectSeed] = interleaveNullableExecutionContextSeeds($container);

    expect($nullSeed)->toBeNull()
        ->and($objectSeed)->toBeInstanceOf(ExecutionContextScopedLeaf::class);
});

it('preserves null seed isolation across compiled Fibers', function () {
    $builder = ContainerBuilder::create(uniqid('context_null_compiled_'))
        ->scoped('nullable', ExecutionContextScopedLeaf::class);
    $path = executionContextArtifactPath();

    try {
        $builder->compile($path);
        $runtime = $builder->production($path);
        [$nullSeed, $objectSeed] = interleaveNullableExecutionContextSeeds($runtime);

        expect($nullSeed)->toBeNull()
            ->and($objectSeed)->toBeInstanceOf(ExecutionContextScopedLeaf::class);
    } finally {
        removeExecutionContextArtifact($path);
    }
});

it('cleans dynamic Fiber scope state when withinScope throws', function () {
    $container = new Container(uniqid('context_throw_dynamic_'));
    $container->scoped('leaf', ExecutionContextScopedLeaf::class);

    [$beforeFailure, $afterFailure] = executionContextThrowableCleanup($container);

    expect($beforeFailure)->toBeInstanceOf(ExecutionContextScopedLeaf::class)
        ->and($afterFailure)->toBeInstanceOf(ExecutionContextScopedLeaf::class)
        ->and($afterFailure)->not->toBe($beforeFailure);
});

it('cleans compiled Fiber scope state when withinScope throws', function () {
    $builder = ContainerBuilder::create(uniqid('context_throw_compiled_'))
        ->scoped('leaf', ExecutionContextScopedLeaf::class);
    $path = executionContextArtifactPath();

    try {
        $builder->compile($path);
        $runtime = $builder->production($path);
        [$beforeFailure, $afterFailure] = executionContextThrowableCleanup($runtime);

        expect($beforeFailure)->toBeInstanceOf(ExecutionContextScopedLeaf::class)
            ->and($afterFailure)->toBeInstanceOf(ExecutionContextScopedLeaf::class)
            ->and($afterFailure)->not->toBe($beforeFailure);
    } finally {
        removeExecutionContextArtifact($path);
    }
});

it('creates fresh dynamic roots for repeated Fibers using the same scope name', function () {
    $container = new Container(uniqid('context_repeat_dynamic_'));
    $container->scoped('leaf', ExecutionContextScopedLeaf::class);

    $resolved = repeatedExecutionContextScopeRoots($container);
    $objectIds = array_map(spl_object_id(...), $resolved);

    expect(array_unique($objectIds))->toHaveCount(count($resolved));
});

it('creates fresh compiled roots for repeated Fibers using the same scope name', function () {
    $builder = ContainerBuilder::create(uniqid('context_repeat_compiled_'))
        ->scoped('leaf', ExecutionContextScopedLeaf::class);
    $path = executionContextArtifactPath();

    try {
        $builder->compile($path);
        $runtime = $builder->production($path);
        $resolved = repeatedExecutionContextScopeRoots($runtime);
        $objectIds = array_map(spl_object_id(...), $resolved);

        expect(array_unique($objectIds))->toHaveCount(count($resolved));
    } finally {
        removeExecutionContextArtifact($path);
    }
});

it('dispatches compiled scope leave hooks for sequential and Fiber scopes', function () {
    $calls = [];
    $builder = ContainerBuilder::create(uniqid('context_compiled_hooks_'))
        ->scoped('leaf', ExecutionContextScopedLeaf::class)
        ->onScopeLeave(
            'request',
            static function (string $scope, Container $activeContainer) use (&$calls): void {
                $calls[] = [$scope, Fiber::getCurrent() instanceof Fiber, $activeContainer instanceof Container];
            },
        );
    $path = executionContextArtifactPath();

    try {
        $builder->compile($path);
        $runtime = $builder->production($path);
        $runtime->enterScope('request');
        $runtime->leaveScope();

        $fiber = new Fiber(static function () use ($runtime): void {
            $runtime->enterScope('request');
            $runtime->leaveScope();
        });
        $fiber->start();

        expect($calls)->toBe([
            ['request', false, true],
            ['request', true, true],
        ]);
    } finally {
        removeExecutionContextArtifact($path);
    }
});
