<?php

declare(strict_types=1);

use Fiber;
use Infocyph\InterMix\DI\Container;
use Infocyph\InterMix\DI\ScopeContext;
use Infocyph\InterMix\Exceptions\ContainerException;

it('distinguishes physical carrier isolation from explicit logical scope sharing', function () {
    $container = new Container(uniqid('scope_context_identity_'));
    $container->scoped('leaf', stdClass::class);
    $container->enterScope('request');

    $parent = $container->get('leaf');
    $context = $container->captureScopeContext();

    $isolated = new Fiber(static function () use ($container): stdClass {
        $container->enterScope('request');
        $leaf = $container->get('leaf');
        $container->leaveScope();

        return $leaf;
    });
    $isolated->start();

    $attached = new Fiber(static fn(): stdClass => $container->withinScopeContext(
        $context,
        static fn(Container $active): stdClass => $active->get('leaf'),
    ));
    $attached->start();

    expect($isolated->getReturn())->not->toBe($parent)
        ->and($attached->getReturn())->toBe($parent);

    $container->leaveScope();
});

it('captures an opaque scope context only from an active logical scope', function () {
    $container = new Container(uniqid('scope_context_capture_'));

    expect(fn() => $container->captureScopeContext())
        ->toThrow(ContainerException::class, 'without an active scope');

    $container->enterScope('request');
    $context = $container->captureScopeContext();

    expect($context)->toBeInstanceOf(ScopeContext::class);

    $container->leaveScope();
});

it('preserves materialized scoped identity and seeds when sequential state is promoted', function () {
    $container = new Container(uniqid('scope_context_promotion_'));
    $container->scoped('leaf', stdClass::class)
        ->scoped('nullable', stdClass::class);
    $container->enterScope('request', ['nullable' => null]);

    $beforeCapture = $container->get('leaf');
    $context = $container->captureScopeContext();
    $afterCapture = $container->get('leaf');

    $child = new Fiber(static fn(): array => $container->withinScopeContext(
        $context,
        static fn(Container $active): array => [
            $active->get('leaf'),
            $active->get('nullable'),
        ],
    ));
    $child->start();
    [$childLeaf, $childNull] = $child->getReturn();

    expect($afterCapture)->toBe($beforeCapture)
        ->and($childLeaf)->toBe($beforeCapture)
        ->and($childNull)->toBeNull();

    $container->leaveScope();
});

it('shares one logical scope across sibling execution carriers only when explicitly attached', function () {
    $container = new Container(uniqid('scope_context_siblings_'));
    $container->scoped('leaf', stdClass::class);
    $container->enterScope('request');
    $context = $container->captureScopeContext();

    $fiberA = new Fiber(static fn(): stdClass => $container->withinScopeContext(
        $context,
        static fn(Container $active): stdClass => $active->get('leaf'),
    ));
    $fiberB = new Fiber(static fn(): stdClass => $container->withinScopeContext(
        $context,
        static fn(Container $active): stdClass => $active->get('leaf'),
    ));

    $fiberA->start();
    $fiberB->start();

    expect($fiberA->getReturn())->toBe($fiberB->getReturn())
        ->and($container->get('leaf'))->toBe($fiberA->getReturn());

    $container->leaveScope();
});

it('propagates a logical scope captured from a parent Fiber to a child Fiber', function () {
    $container = new Container(uniqid('scope_context_fiber_parent_'));
    $container->scoped('leaf', stdClass::class);

    $parentFiber = new Fiber(static function () use ($container): array {
        $container->enterScope('request');
        $parent = $container->get('leaf');
        $context = $container->captureScopeContext();

        $childFiber = new Fiber(static fn(): stdClass => $container->withinScopeContext(
            $context,
            static fn(Container $active): stdClass => $active->get('leaf'),
        ));
        $childFiber->start();
        $child = $childFiber->getReturn();

        $container->leaveScope();

        return [$parent, $child];
    });
    $parentFiber->start();
    [$parent, $child] = $parentFiber->getReturn();

    expect($child)->toBe($parent);
});

it('rejects a scope context owned by another container', function () {
    $owner = new Container(uniqid('scope_context_owner_'));
    $foreign = new Container(uniqid('scope_context_foreign_'));
    $owner->enterScope('request');
    $context = $owner->captureScopeContext();

    $fiber = new Fiber(static fn(): mixed => $foreign->withinScopeContext(
        $context,
        static fn(Container $active): mixed => $active,
    ));

    expect(fn() => $fiber->start())
        ->toThrow(ContainerException::class, 'different container');

    $owner->leaveScope();
});

it('rejects a captured context after its owning scope closes', function () {
    $container = new Container(uniqid('scope_context_stale_'));
    $container->enterScope('request');
    $context = $container->captureScopeContext();
    $container->leaveScope();

    $fiber = new Fiber(static fn(): mixed => $container->withinScopeContext(
        $context,
        static fn(Container $active): mixed => $active,
    ));

    expect(fn() => $fiber->start())
        ->toThrow(ContainerException::class, 'no longer active');
});

it('does not allow scope contexts to be serialized', function () {
    $container = new Container(uniqid('scope_context_serialize_'));
    $container->enterScope('request');
    $context = $container->captureScopeContext();

    expect(fn() => serialize($context))
        ->toThrow(ContainerException::class, 'cannot be serialized');

    $container->leaveScope();
});

it('detaches an attached scope context when the child callback throws', function () {
    $container = new Container(uniqid('scope_context_throw_'));
    $container->scoped('leaf', stdClass::class);
    $container->enterScope('request');
    $parent = $container->get('leaf');
    $context = $container->captureScopeContext();

    $fiber = new Fiber(static function () use ($container, $context): stdClass {
        try {
            $container->withinScopeContext(
                $context,
                static function (Container $active): never {
                    $active->get('leaf');

                    throw new RuntimeException('expected child failure');
                },
            );
        } catch (RuntimeException $exception) {
            if ($exception->getMessage() !== 'expected child failure') {
                throw $exception;
            }
        }

        $container->enterScope('independent');
        $fresh = $container->get('leaf');
        $container->leaveScope();

        return $fresh;
    });
    $fiber->start();

    expect($fiber->getReturn())->not->toBe($parent);

    $container->leaveScope();
});

it('unwinds nested child scopes before detaching an attached context after failure', function () {
    $container = new Container(uniqid('scope_context_nested_throw_'));
    $container->scoped('leaf', stdClass::class);
    $nestedLeaves = [];
    $container->onScopeLeave(
        'nested',
        static function (string $scope) use (&$nestedLeaves): void {
            $nestedLeaves[] = $scope;
        },
    );
    $container->enterScope('request');
    $parent = $container->get('leaf');
    $context = $container->captureScopeContext();

    $fiber = new Fiber(static function () use ($container, $context): stdClass {
        try {
            $container->withinScopeContext(
                $context,
                static function (Container $active): never {
                    $active->enterScope('nested');
                    $active->get('leaf');

                    throw new RuntimeException('expected nested failure');
                },
            );
        } catch (RuntimeException $exception) {
            if ($exception->getMessage() !== 'expected nested failure') {
                throw $exception;
            }
        }

        $container->enterScope('independent');
        $fresh = $container->get('leaf');
        $container->leaveScope();

        return $fresh;
    });
    $fiber->start();

    expect($nestedLeaves)->toBe(['nested'])
        ->and($fiber->getReturn())->not->toBe($parent);

    $container->leaveScope();
});

it('rejects attachment when the target carrier already owns a scope', function () {
    $container = new Container(uniqid('scope_context_busy_'));
    $container->enterScope('request');
    $context = $container->captureScopeContext();

    $fiber = new Fiber(static function () use ($container, $context): void {
        $container->enterScope('independent');

        try {
            $container->withinScopeContext($context, static fn(): null => null);
        } finally {
            $container->leaveScope();
        }
    });

    expect(fn() => $fiber->start())
        ->toThrow(ContainerException::class, 'already has an active scope');

    $container->leaveScope();
});
