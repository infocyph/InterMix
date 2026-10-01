<?php

declare(strict_types=1);

use Fiber;
use Infocyph\InterMix\DI\Container;
use Infocyph\InterMix\DI\ContainerBuilder;
use Infocyph\InterMix\DI\Support\LifetimeEnum;

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
        testEnterScope($container, 'request', ['seeded' => $seedA]);
        $first = $container->get('leaf');
        $seeded = $container->get('seeded');
        Fiber::suspend();
        $again = $container->get('leaf');
        $seededAgain = $container->get('seeded');
        testLeaveScope($container);

        return [$first, $again, $seeded, $seededAgain];
    });

    $fiberB = new Fiber(static function () use ($container, $seedB): array {
        testEnterScope($container, 'request', ['seeded' => $seedB]);
        $first = $container->get('leaf');
        $seeded = $container->get('seeded');
        Fiber::suspend();
        $again = $container->get('leaf');
        $seededAgain = $container->get('seeded');
        testLeaveScope($container);

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
        testEnterScope($container, 'request');
        $parent = $container->get('leaf');
        Fiber::suspend();

        testEnterScope($container, 'nested-a');
        $nested = $container->get('leaf');
        Fiber::suspend();

        testLeaveScope($container);
        $restored = $container->get('leaf');
        testLeaveScope($container);

        return [$parent, $nested, $restored];
    });

    $fiberB = new Fiber(static function () use ($container): array {
        testEnterScope($container, 'request');
        $parent = $container->get('leaf');
        Fiber::suspend();

        testEnterScope($container, 'nested-b');
        $nested = $container->get('leaf');
        Fiber::suspend();

        testLeaveScope($container);
        $restored = $container->get('leaf');
        testLeaveScope($container);

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
        testEnterScope($container, 'request', ['nullable' => null]);
        $seed = $container->get('nullable');
        Fiber::suspend();
        testLeaveScope($container);

        return $seed;
    });

    $fiberB = new Fiber(static function () use ($container, $seedB): mixed {
        testEnterScope($container, 'request', ['nullable' => $seedB]);
        $seed = $container->get('nullable');
        Fiber::suspend();
        testLeaveScope($container);

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

        testEnterScope($container, 'request');
        $second = $container->get('leaf');
        testLeaveScope($container);

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
            testEnterScope($container, 'request');
            $leaf = $container->get('leaf');
            testLeaveScope($container);

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
    $container = ContainerBuilder::create(uniqid('context_dynamic_'))
        ->releaseIdentity('intermix-test')
        ->autowire('leaf', ExecutionContextScopedLeaf::class, lifetime: LifetimeEnum::Scoped)
        ->autowire('seeded', ExecutionContextScopedLeaf::class, lifetime: LifetimeEnum::Scoped)
        ->build();

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
        ->releaseIdentity('intermix-test')
        ->autowire('leaf', ExecutionContextScopedLeaf::class, lifetime: LifetimeEnum::Scoped)
        ->autowire('seeded', ExecutionContextScopedLeaf::class, lifetime: LifetimeEnum::Scoped);
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
    $container = ContainerBuilder::create(uniqid('context_mixed_'))
        ->releaseIdentity('intermix-test')
        ->autowire('leaf', ExecutionContextScopedLeaf::class, lifetime: LifetimeEnum::Scoped)
        ->autowire('seeded', ExecutionContextScopedLeaf::class, lifetime: LifetimeEnum::Scoped)
        ->build();

    $beforeSeed = new ExecutionContextScopedLeaf();
    testEnterScope($container, 'request', ['seeded' => $beforeSeed]);
    $before = $container->get('leaf');
    $beforeAgain = $container->get('leaf');
    $beforeSeeded = $container->get('seeded');
    testLeaveScope($container);

    $fibers = interleaveExecutionContextScopes($container);

    $afterSeed = new ExecutionContextScopedLeaf();
    testEnterScope($container, 'request', ['seeded' => $afterSeed]);
    $after = $container->get('leaf');
    $afterSeeded = $container->get('seeded');
    testLeaveScope($container);

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
    $calls = [];
    $container = null;
    $builder = ContainerBuilder::create(uniqid('context_hooks_'))
        ->releaseIdentity('intermix-test')
        ->onScopeLeave(
            'request',
            static function (string $scope, Container $activeContainer) use (&$calls, &$container): void {
                $calls[] = [$scope, Fiber::getCurrent() instanceof Fiber, $activeContainer === $container];
            },
        );
    $container = $builder->build();

    testEnterScope($container, 'request');
    testLeaveScope($container);

    $fiber = new Fiber(static function () use ($container): void {
        testEnterScope($container, 'request');
        testLeaveScope($container);
    });
    $fiber->start();

    expect($calls)->toBe([
        ['request', false, true],
        ['request', true, true],
    ]);
});

it('keeps nested dynamic Fiber scope stacks independent', function () {
    $container = ContainerBuilder::create(uniqid('context_nested_dynamic_'))
        ->releaseIdentity('intermix-test')
        ->autowire('leaf', ExecutionContextScopedLeaf::class, lifetime: LifetimeEnum::Scoped)
        ->build();

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
        ->releaseIdentity('intermix-test')
        ->autowire('leaf', ExecutionContextScopedLeaf::class, lifetime: LifetimeEnum::Scoped);
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
    $container = ContainerBuilder::create(uniqid('context_null_dynamic_'))
        ->releaseIdentity('intermix-test')
        ->autowire('nullable', ExecutionContextScopedLeaf::class, lifetime: LifetimeEnum::Scoped)
        ->build();

    [$nullSeed, $objectSeed] = interleaveNullableExecutionContextSeeds($container);

    expect($nullSeed)->toBeNull()
        ->and($objectSeed)->toBeInstanceOf(ExecutionContextScopedLeaf::class);
});

it('preserves null seed isolation across compiled Fibers', function () {
    $builder = ContainerBuilder::create(uniqid('context_null_compiled_'))
        ->releaseIdentity('intermix-test')
        ->autowire('nullable', ExecutionContextScopedLeaf::class, lifetime: LifetimeEnum::Scoped);
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
    $container = ContainerBuilder::create(uniqid('context_throw_dynamic_'))
        ->releaseIdentity('intermix-test')
        ->autowire('leaf', ExecutionContextScopedLeaf::class, lifetime: LifetimeEnum::Scoped)
        ->build();

    [$beforeFailure, $afterFailure] = executionContextThrowableCleanup($container);

    expect($beforeFailure)->toBeInstanceOf(ExecutionContextScopedLeaf::class)
        ->and($afterFailure)->toBeInstanceOf(ExecutionContextScopedLeaf::class)
        ->and($afterFailure)->not->toBe($beforeFailure);
});

it('cleans compiled Fiber scope state when withinScope throws', function () {
    $builder = ContainerBuilder::create(uniqid('context_throw_compiled_'))
        ->releaseIdentity('intermix-test')
        ->autowire('leaf', ExecutionContextScopedLeaf::class, lifetime: LifetimeEnum::Scoped);
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
    $container = ContainerBuilder::create(uniqid('context_repeat_dynamic_'))
        ->releaseIdentity('intermix-test')
        ->autowire('leaf', ExecutionContextScopedLeaf::class, lifetime: LifetimeEnum::Scoped)
        ->build();

    $resolved = repeatedExecutionContextScopeRoots($container);
    $objectIds = array_map(spl_object_id(...), $resolved);

    expect(array_unique($objectIds))->toHaveCount(count($resolved));
});

it('creates fresh compiled roots for repeated Fibers using the same scope name', function () {
    $builder = ContainerBuilder::create(uniqid('context_repeat_compiled_'))
        ->releaseIdentity('intermix-test')
        ->autowire('leaf', ExecutionContextScopedLeaf::class, lifetime: LifetimeEnum::Scoped);
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
        ->releaseIdentity('intermix-test')
        ->autowire('leaf', ExecutionContextScopedLeaf::class, lifetime: LifetimeEnum::Scoped)
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
        testEnterScope($runtime, 'request');
        testLeaveScope($runtime);

        $fiber = new Fiber(static function () use ($runtime): void {
            testEnterScope($runtime, 'request');
            testLeaveScope($runtime);
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
