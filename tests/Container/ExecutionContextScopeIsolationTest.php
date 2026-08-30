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
