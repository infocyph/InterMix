<?php

declare(strict_types=1);

use Fiber;
use Infocyph\InterMix\DI\Container;
use Infocyph\InterMix\DI\Support\LifetimeEnum;
use Infocyph\InterMix\Exceptions\ContainerException;

final class StructuredScopeLeaf {}

it('keeps nested child frames carrier-local while sharing the attached parent scope', function () {
    $container = new Container(uniqid('structured_nested_'));
    $container->scoped('leaf', StructuredScopeLeaf::class);
    $container->enterScope('request');
    $parent = $container->get('leaf');
    $context = $container->captureScopeContext();

    $child = static function () use ($container, $context): array {
        return $container->withinScopeContext($context, static function (Container $active): array {
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

    expect($nestedA)->toBeInstanceOf(StructuredScopeLeaf::class)
        ->and($nestedB)->toBeInstanceOf(StructuredScopeLeaf::class)
        ->and($nestedA)->not->toBe($parent)
        ->and($nestedB)->not->toBe($parent)
        ->and($nestedA)->not->toBe($nestedB)
        ->and($container->get('leaf'))->toBe($parent);

    $restoredA = $fiberA->resume();
    expect($restoredA)->toBe($parent)
        ->and($fiberB->isSuspended())->toBeTrue()
        ->and($container->get('leaf'))->toBe($parent);

    $restoredB = $fiberB->resume();
    expect($restoredB)->toBe($parent);

    $fiberA->resume();
    $fiberB->resume();

    expect($fiberA->getReturn()[0])->toBe($nestedA)
        ->and($fiberA->getReturn()[1])->toBe($parent)
        ->and($fiberB->getReturn()[0])->toBe($nestedB)
        ->and($fiberB->getReturn()[1])->toBe($parent);

    $container->leaveScope();
});

it('rejects owner close while a child attachment is live without firing the owner hook', function () {
    $container = new Container(uniqid('structured_owner_live_'));
    $container->scoped('leaf', StructuredScopeLeaf::class);
    $leaves = [];
    $container->onScopeLeave('request', static function (string $scope) use (&$leaves): void {
        $leaves[] = $scope;
    });
    $container->enterScope('request');
    $parent = $container->get('leaf');
    $context = $container->captureScopeContext();

    $child = new Fiber(static fn(): StructuredScopeLeaf => $container->withinScopeContext(
        $context,
        static function (Container $active): StructuredScopeLeaf {
            $leaf = $active->get('leaf');
            Fiber::suspend();

            return $leaf;
        },
    ));
    $child->start();

    expect(fn() => $container->leaveScope())
        ->toThrow(ContainerException::class, 'child execution carriers are still attached');
    expect($leaves)->toBe([])
        ->and($container->get('leaf'))->toBe($parent);

    $child->resume();
    expect($child->getReturn())->toBe($parent);

    $container->leaveScope();
    expect($leaves)->toBe(['request']);
});

it('fails deterministically when sibling carriers cold-resolve the same scoped service', function () {
    $container = new Container(uniqid('structured_construct_'));
    $calls = 0;
    $container->bindFactory(
        'cold',
        static function () use (&$calls): stdClass {
            ++$calls;
            if ($calls === 1 && Fiber::getCurrent() instanceof Fiber) {
                Fiber::suspend();
            }

            return new stdClass();
        },
        LifetimeEnum::Scoped,
    );
    $container->enterScope('request');
    $context = $container->captureScopeContext();

    $first = new Fiber(static fn(): stdClass => $container->withinScopeContext(
        $context,
        static fn(Container $active): stdClass => $active->get('cold'),
    ));
    $first->start();
    expect($first->isSuspended())->toBeTrue();

    $second = new Fiber(static fn(): stdClass => $container->withinScopeContext(
        $context,
        static fn(Container $active): stdClass => $active->get('cold'),
    ));
    expect(fn() => $second->start())
        ->toThrow(ContainerException::class, 'already being constructed by another execution carrier');

    $first->resume();
    $resolved = $first->getReturn();

    expect($resolved)->toBeInstanceOf(stdClass::class)
        ->and($container->get('cold'))->toBe($resolved)
        ->and($calls)->toBe(1);

    $container->leaveScope();
});

it('clears a failed scoped construction guard so a later carrier can retry', function () {
    $container = new Container(uniqid('structured_construct_failure_'));
    $calls = 0;
    $container->bindFactory(
        'cold',
        static function () use (&$calls): stdClass {
            ++$calls;
            if ($calls === 1) {
                throw new RuntimeException('expected construction failure');
            }

            return new stdClass();
        },
        LifetimeEnum::Scoped,
    );
    $container->enterScope('request');
    $context = $container->captureScopeContext();

    $first = new Fiber(static fn(): stdClass => $container->withinScopeContext(
        $context,
        static fn(Container $active): stdClass => $active->get('cold'),
    ));
    expect(fn() => $first->start())
        ->toThrow(RuntimeException::class, 'expected construction failure');

    $second = new Fiber(static fn(): stdClass => $container->withinScopeContext(
        $context,
        static fn(Container $active): stdClass => $active->get('cold'),
    ));
    $second->start();

    expect($second->getReturn())->toBeInstanceOf(stdClass::class)
        ->and($calls)->toBe(2);

    $container->leaveScope();
});

it('resets only the current owned execution carrier in LIFO hook order and is idempotent', function () {
    $container = new Container(uniqid('structured_reset_owned_'));
    $leaves = [];
    foreach (['request', 'nested'] as $scope) {
        $container->onScopeLeave($scope, static function (string $left) use (&$leaves): void {
            $leaves[] = $left;
        });
    }

    $fiber = new Fiber(static function () use ($container): array {
        $container->enterScope('request');
        $container->enterScope('nested');
        $container->resetCurrentExecutionScope();
        $container->resetCurrentExecutionScope();

        $container->enterScope('fresh');
        $scope = $container->getRepository()->getScope();
        $container->leaveScope();

        return [$scope, $container->getRepository()->getScope()];
    });
    $fiber->start();

    expect($leaves)->toBe(['nested', 'request'])
        ->and($fiber->getReturn())->toBe(['fresh', 'root']);
});

it('resets an attached carrier by unwinding child frames and detaching without closing the owner', function () {
    $container = new Container(uniqid('structured_reset_attached_'));
    $container->scoped('leaf', StructuredScopeLeaf::class);
    $leaves = [];
    foreach (['request', 'nested'] as $scope) {
        $container->onScopeLeave($scope, static function (string $left) use (&$leaves): void {
            $leaves[] = $left;
        });
    }
    $container->enterScope('request');
    $parent = $container->get('leaf');
    $context = $container->captureScopeContext();

    $child = new Fiber(static function () use ($container, $context): StructuredScopeLeaf {
        $container->withinScopeContext($context, static function (Container $active): void {
            $active->enterScope('nested');
            $active->get('leaf');
            $active->resetCurrentExecutionScope();
            $active->resetCurrentExecutionScope();
        });

        $container->enterScope('independent');
        $fresh = $container->get('leaf');
        $container->leaveScope();

        return $fresh;
    });
    $child->start();

    expect($leaves)->toBe(['nested'])
        ->and($child->getReturn())->not->toBe($parent)
        ->and($container->get('leaf'))->toBe($parent);

    $container->leaveScope();
    expect($leaves)->toBe(['nested', 'request']);
});
