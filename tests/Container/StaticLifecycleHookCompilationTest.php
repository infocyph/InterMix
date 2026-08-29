<?php

declare(strict_types=1);

use Infocyph\InterMix\DI\Build\StaticRuntimeGenerator;
use Infocyph\InterMix\DI\Container;
use Infocyph\InterMix\DI\ContainerBuilder;
use Infocyph\InterMix\DI\Support\LifetimeEnum;
use Infocyph\InterMix\Exceptions\ContainerException;

final class StaticHookCompiledService {}

final class StaticHookInvocationService
{
    public function execute(): string
    {
        return 'invoked';
    }
}

function staticLifecycleHookArtifactPath(): string
{
    return sys_get_temp_dir() . '/intermix-static-hooks-' . bin2hex(random_bytes(8)) . '.php';
}

function removeStaticLifecycleHookArtifact(string $path): void
{
    foreach ([$path, $path . '.meta.json'] as $artifact) {
        if (is_file($artifact)) {
            unlink($artifact);
        }
    }
}

it('keeps hooked singleton services compiled and dispatches hooks only on cache miss', function () {
    $events = [];
    $builder = ContainerBuilder::create(uniqid('static_hook_singleton_'));
    $builder->singleton('service', StaticHookCompiledService::class);
    $builder->onResolving('service', function (string $id) use (&$events): void {
        $events[] = "resolving:$id";
    });
    $builder->onResolved('service', function (string $id, mixed $value) use (&$events): void {
        $events[] = $value instanceof StaticHookCompiledService ? "resolved:$id" : 'invalid';
    });
    $path = staticLifecycleHookArtifactPath();

    try {
        $report = $builder->compile($path);
        $runtime = $builder->productionPrevalidated($path, $report['sha256']);

        $first = $runtime->get('service');
        $second = $runtime->get('service');

        expect($report['compiled'])->toContain('service')
            ->and($first)->toBeInstanceOf(StaticHookCompiledService::class)
            ->and($second)->toBe($first)
            ->and($events)->toBe(['resolving:service', 'resolved:service']);
    } finally {
        removeStaticLifecycleHookArtifact($path);
    }
});

it('captures hooks registered through a retained development container', function () {
    $events = [];
    $builder = ContainerBuilder::create(uniqid('static_hook_development_'));
    $development = $builder->development();
    $builder->singleton('service', StaticHookCompiledService::class);
    $development->onResolved('service', function (string $id) use (&$events): void {
        $events[] = $id;
    });
    $path = staticLifecycleHookArtifactPath();

    try {
        $report = $builder->compile($path);
        $runtime = $builder->productionPrevalidated($path, $report['sha256']);

        expect($runtime->get('service'))->toBeInstanceOf(StaticHookCompiledService::class)
            ->and($events)->toBe(['service']);
    } finally {
        removeStaticLifecycleHookArtifact($path);
    }
});

it('dispatches compiled transient value hooks for every resolution', function () {
    $events = [];
    $builder = ContainerBuilder::create(uniqid('static_hook_value_'));
    $builder->bind('answer', 42, LifetimeEnum::Transient);
    $builder->onResolving('answer', function (string $id) use (&$events): void {
        $events[] = "resolving:$id";
    });
    $builder->onResolved('answer', function (string $id, mixed $value) use (&$events): void {
        $events[] = "resolved:$id:$value";
    });
    $path = staticLifecycleHookArtifactPath();

    try {
        $report = $builder->compile($path);
        $runtime = $builder->productionPrevalidated($path, $report['sha256']);

        expect($runtime->get('answer'))->toBe(42)
            ->and($runtime->get('answer'))->toBe(42)
            ->and($report['compiled'])->toContain('answer')
            ->and($events)->toBe([
                'resolving:answer',
                'resolved:answer:42',
                'resolving:answer',
                'resolved:answer:42',
            ]);
    } finally {
        removeStaticLifecycleHookArtifact($path);
    }
});

it('preserves scoped hook and scope-leave semantics in production', function () {
    $resolved = 0;
    $left = [];
    $builder = ContainerBuilder::create(uniqid('static_hook_scope_'));
    $development = $builder->development();
    $builder->scoped('service', StaticHookCompiledService::class);
    $builder->onResolved('service', function () use (&$resolved): void {
        ++$resolved;
    });
    $builder->onScopeLeave('request', function (string $scope, Container $container) use (&$left, $development): void {
        $left[] = [$scope, $container === $development];
    });
    $path = staticLifecycleHookArtifactPath();

    try {
        $report = $builder->compile($path);
        $runtime = $builder->productionPrevalidated($path, $report['sha256']);

        $runtime->enterScope('request');
        $first = $runtime->get('service');
        expect($runtime->get('service'))->toBe($first);
        $runtime->leaveScope();

        $runtime->enterScope('request');
        $second = $runtime->get('service');
        $runtime->leaveScope();

        expect($report['compiled'])->toContain('service')
            ->and($second)->not->toBe($first)
            ->and($resolved)->toBe(2)
            ->and($left)->toBe([
                ['request', true],
                ['request', true],
            ]);
    } finally {
        removeStaticLifecycleHookArtifact($path);
    }
});

it('dispatches lifecycle hooks around compiled invocation results', function () {
    $events = [];
    $builder = ContainerBuilder::create(uniqid('static_hook_invocation_'));
    $builder->bind('invocation', [StaticHookInvocationService::class, 'execute']);
    $builder->onResolving('invocation', function (string $id) use (&$events): void {
        $events[] = "resolving:$id";
    });
    $builder->onResolved('invocation', function (string $id, mixed $value) use (&$events): void {
        $events[] = "resolved:$id:$value";
    });
    $path = staticLifecycleHookArtifactPath();

    try {
        $report = $builder->compile($path);
        $runtime = $builder->productionPrevalidated($path, $report['sha256']);

        expect($runtime->get('invocation'))->toBe('invoked')
            ->and($runtime->get('invocation'))->toBe('invoked')
            ->and($report['compiled'])->toContain('invocation')
            ->and($events)->toBe(['resolving:invocation', 'resolved:invocation:invoked']);
    } finally {
        removeStaticLifecycleHookArtifact($path);
    }
});

it('fails closed when a hooked artifact is loaded without its runtime hook graph', function () {
    $builder = ContainerBuilder::create(uniqid('static_hook_missing_runtime_'));
    $builder->singleton('service', StaticHookCompiledService::class);
    $builder->onResolved('service', static function (): void {});
    $builder->onScopeLeave('request', static function (): void {});
    $path = staticLifecycleHookArtifactPath();

    try {
        $report = $builder->compile($path);
        $runtime = new StaticRuntimeGenerator()->loadPrevalidated($path, $report['sha256']);

        expect(fn() => $runtime->get('service'))
            ->toThrow(ContainerException::class, 'runtime lifecycle-hook graph');

        $runtime->enterScope('request');
        expect(fn() => $runtime->leaveScope())
            ->toThrow(ContainerException::class, 'runtime scope-leave hook graph');
    } finally {
        removeStaticLifecycleHookArtifact($path);
    }
});

it('emits no lifecycle-hook calls for an artifact without hooks', function () {
    $builder = ContainerBuilder::create(uniqid('static_hook_free_'));
    $builder->singleton('service', StaticHookCompiledService::class);
    $path = staticLifecycleHookArtifactPath();

    try {
        $report = $builder->compile($path);
        $source = file_get_contents($path);

        expect($report['compiled'])->toContain('service')
            ->and($source)->toBeString()
            ->and($source)->not->toContain('dispatchCompiledResolvingHooks')
            ->and($source)->not->toContain('dispatchCompiledResolvedHooks')
            ->and($source)->not->toContain('requiresScopeLeaveHook');
    } finally {
        removeStaticLifecycleHookArtifact($path);
    }
});
