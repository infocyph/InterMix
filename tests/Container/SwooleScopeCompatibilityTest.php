<?php

declare(strict_types=1);

use Infocyph\InterMix\DI\Container;
use Infocyph\InterMix\DI\Internal\ExecutionContext;

final class SwooleCompatibilityScopedLeaf {}

/** @return null|array{class-string, string} */
function swooleCompatibilityRuntime(): ?array
{
    if (class_exists('Swoole\\Coroutine', false)) {
        return ['Swoole\\Coroutine', 'Swoole'];
    }
    if (class_exists('OpenSwoole\\Coroutine', false)) {
        return ['OpenSwoole\\Coroutine', 'OpenSwoole'];
    }

    return null;
}

it('keeps Swoole family carriers stable and distinct when the optional extension is loaded', function (): void {
    $runtime = swooleCompatibilityRuntime();
    if ($runtime === null) {
        expect(ExecutionContext::id())->toBeNull();

        return;
    }

    [$coroutineClass, $namespace] = $runtime;
    $run = $namespace . '\\Coroutine\\run';
    expect(function_exists($run))->toBeTrue();

    $pairs = [];
    $fiberIds = [];

    $run(static function () use ($coroutineClass, &$fiberIds, &$pairs): void {
        /** @var callable(callable(): void): int|false $create */
        $create = [$coroutineClass, 'create'];

        for ($iteration = 0; $iteration < 32; ++$iteration) {
            $created = $create(static function () use (&$fiberIds, &$pairs): void {
                $first = ExecutionContext::id();
                $second = ExecutionContext::id();
                $pairs[] = [$first, $second];

                $fiber = new Fiber(static fn(): ?string => ExecutionContext::id());
                $fiber->start();
                $fiberIds[] = $fiber->getReturn();
            });
            expect($created)->not->toBeFalse();
        }
    });

    $coroutineIds = [];
    foreach ($pairs as [$first, $second]) {
        expect($first)->toBeString()
            ->and($second)->toBe($first)
            ->and(str_starts_with($first, 'fiber:'))->toBeFalse();
        $coroutineIds[] = $first;
    }

    expect($pairs)->toHaveCount(32)
        ->and(array_unique($coroutineIds))->toHaveCount(32)
        ->and($fiberIds)->toHaveCount(32);

    foreach ($fiberIds as $fiberId) {
        expect($fiberId)->toBeString()
            ->and(str_starts_with($fiberId, 'fiber:'))->toBeTrue();
    }
});

it('shares a logical scope explicitly across a Swoole family child without changing default isolation', function (): void {
    $runtime = swooleCompatibilityRuntime();
    if ($runtime === null) {
        expect(true)->toBeTrue();

        return;
    }

    [$coroutineClass, $namespace] = $runtime;
    $run = $namespace . '\\Coroutine\\run';
    $container = new Container(uniqid('swoole_scope_'));
    $container->scoped('leaf', SwooleCompatibilityScopedLeaf::class);
    $shared = null;
    $isolated = null;

    $run(static function () use ($container, $coroutineClass, &$isolated, &$shared): void {
        /** @var callable(callable(): void): int|false $create */
        $create = [$coroutineClass, 'create'];
        /** @var callable(float): mixed $sleep */
        $sleep = [$coroutineClass, 'sleep'];

        $container->enterScope('request');
        $parent = $container->get('leaf');
        $context = $container->captureScopeContext();
        $remaining = 2;

        $created = $create(static function () use ($container, $context, &$remaining, &$shared): void {
            try {
                $shared = $container->withinScopeContext(
                    $context,
                    static fn(Container $active): object => $active->get('leaf'),
                );
            } finally {
                --$remaining;
            }
        });
        expect($created)->not->toBeFalse();

        $created = $create(static function () use ($container, &$isolated, &$remaining): void {
            try {
                $container->enterScope('independent');
                try {
                    $isolated = $container->get('leaf');
                } finally {
                    $container->leaveScope();
                }
            } finally {
                --$remaining;
            }
        });
        expect($created)->not->toBeFalse();

        while ($remaining > 0) {
            $sleep(0.001);
        }

        expect($shared)->toBe($parent)
            ->and($isolated)->not->toBe($parent);

        $container->leaveScope();
    });
});
