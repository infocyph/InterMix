<?php

declare(strict_types=1);

use Infocyph\InterMix\DI\Container;
use Infocyph\InterMix\DI\Internal\ConfigurationContainer;
use Infocyph\InterMix\DI\ContainerBuilder;
use Infocyph\InterMix\DI\Internal\ExecutionContext;
use Infocyph\InterMix\DI\Support\LifetimeEnum;
use Infocyph\InterMix\Exceptions\ContainerException;

final class RuntimeAlignmentCompiledLeaf {}

final class RuntimeAlignmentScopedInput {}

final class RuntimeAlignmentCaptiveSingleton
{
    public function __construct(public RuntimeAlignmentScopedInput $input) {}
}

function runtimeAlignmentArtifactPath(): string
{
    return sys_get_temp_dir() . '/intermix-runtime-alignment-' . bin2hex(random_bytes(8)) . '.php';
}

function removeRuntimeAlignmentArtifact(string $path): void
{
    foreach ([$path, $path . '.meta.json'] as $artifact) {
        if (is_file($artifact)) {
            unlink($artifact);
        }
    }
}

it('keeps live Fiber carrier identities distinct', function () {
    $fibers = [];
    $ids = [];

    for ($i = 0; $i < 64; ++$i) {
        $fiber = new Fiber(static function (): void {
            Fiber::suspend(ExecutionContext::id());
        });
        $ids[] = $fiber->start();
        $fibers[] = $fiber;
    }

    expect(array_filter($ids, is_string(...)))->toHaveCount(64)
        ->and(array_unique($ids))->toHaveCount(64);

    foreach ($fibers as $fiber) {
        $fiber->resume();
    }
});

it('does not reuse collected Fiber carrier identities', function () {
    $ids = [];

    for ($i = 0; $i < 64; ++$i) {
        $fiber = new Fiber(static fn(): ?string => ExecutionContext::id());
        $fiber->start();
        $id = $fiber->getReturn();
        if (is_string($id)) {
            $ids[] = $id;
        }
        unset($fiber);
        gc_collect_cycles();
    }

    expect($ids)->toHaveCount(64)
        ->and(array_unique($ids))->toHaveCount(64);
});

it('does not retain collected Fiber carriers', function () {
    $fiber = new Fiber(static fn(): ?string => ExecutionContext::id());
    $fiber->start();

    expect($fiber->getReturn())->toBeString();

    $reference = \WeakReference::create($fiber);
    unset($fiber);
    gc_collect_cycles();

    expect($reference->get())->toBeNull();
});

it('preserves compiled and fallback scoped identity after builder finalization', function () {
    $builder = ContainerBuilder::create(uniqid('runtime_alignment_fallback_'))
        ->releaseIdentity('intermix-test');
    $builder->autowire('compiled', RuntimeAlignmentCompiledLeaf::class, lifetime: LifetimeEnum::Scoped)
        ->factory('dynamic', static fn(): stdClass => new stdClass(), LifetimeEnum::Scoped);

    $path = runtimeAlignmentArtifactPath();
    try {
        $builder->compile($path);
        $runtime = $builder->production($path);
        testEnterScope($runtime, 'request');
        $compiled = $runtime->get('compiled');
        $dynamic = $runtime->get('dynamic');
        $context = $runtime->captureScopeContext();

        expect(fn() => $builder->value('late.value', 'blocked'))
            ->toThrow(ContainerException::class, 'ContainerBuilder is finalized');

        $fiber = new Fiber(static fn(): array => $runtime->withinScopeContext(
            $context,
            static fn($active): array => [
                $active->get('compiled'),
                $active->get('dynamic'),
            ],
        ));
        $fiber->start();
        [$childCompiled, $childDynamic] = $fiber->getReturn();

        expect($childCompiled)->toBe($compiled)
            ->and($childDynamic)->toBe($dynamic);

        testLeaveScope($runtime);
    } finally {
        removeRuntimeAlignmentArtifact($path);
    }
});

it('rejects dynamic configuration mutation from a foreign carrier while its scope is active', function () {
    $container = new ConfigurationContainer(uniqid('runtime_alignment_dynamic_mutation_'));
    $container->value('stable', 'baseline');

    $fiber = new Fiber(static function () use ($container): void {
        testEnterScope($container, 'request');
        Fiber::suspend();
        testLeaveScope($container);
    });
    $fiber->start();

    expect(fn() => $container->value('late', 'blocked'))
        ->toThrow(ContainerException::class, 'concurrent scope execution is active')
        ->and(fn() => $container->setEnvironment('blocked'))
        ->toThrow(ContainerException::class, 'concurrent scope execution is active')
        ->and($container->getRepository()->hasFunctionReference('late'))->toBeFalse();

    $fiber->resume();
    $container->value('late', 'allowed');

    expect($container->get('late'))->toBe('allowed');
});

it('keeps a finalized compiled graph immutable while a propagated child is attached', function () {
    $builder = ContainerBuilder::create(uniqid('runtime_alignment_compiled_mutation_'))
        ->releaseIdentity('intermix-test');
    $builder->autowire('compiled', RuntimeAlignmentCompiledLeaf::class, lifetime: LifetimeEnum::Scoped);
    $path = runtimeAlignmentArtifactPath();
    try {
        $builder->compile($path);
        $report = $builder->compilationReport();
        $runtime = $builder->production($path);
        testEnterScope($runtime, 'request');
        $context = $runtime->captureScopeContext();

        $child = new Fiber(static fn(): RuntimeAlignmentCompiledLeaf => $runtime->withinScopeContext(
            $context,
            static function ($active): RuntimeAlignmentCompiledLeaf {
                $leaf = $active->get('compiled');
                Fiber::suspend();

                return $leaf;
            },
        ));
        $child->start();

        expect(fn() => $builder->value('late.value', 'blocked'))
            ->toThrow(ContainerException::class, 'ContainerBuilder is finalized')
            ->and($builder->compilationReport())->toBe($report)
            ->and($builder->build()->has('late.value'))->toBeFalse();

        $child->resume();

        expect(fn() => $builder->value('late.value', 'still-blocked'))
            ->toThrow(ContainerException::class, 'ContainerBuilder is finalized')
            ->and($runtime->get('compiled'))->toBeInstanceOf(RuntimeAlignmentCompiledLeaf::class);

        testLeaveScope($runtime);
    } finally {
        removeRuntimeAlignmentArtifact($path);
    }
});

it('rejects singleton autowiring that captures a scoped input during graph finalization', function () {
    $builder = ContainerBuilder::create(uniqid('runtime_alignment_seed_guard_'))
        ->input(RuntimeAlignmentScopedInput::class)
        ->autowire(
            RuntimeAlignmentCaptiveSingleton::class,
            RuntimeAlignmentCaptiveSingleton::class,
            lifetime: LifetimeEnum::Singleton,
        );

    expect(fn() => $builder->build())
        ->toThrow(ContainerException::class, 'depends on scoped entry');
});

it('keeps dynamic singleton factories behind the runtime captive-dependency guard', function () {
    $builder = ContainerBuilder::create(uniqid('runtime_alignment_dynamic_seed_guard_'))
        ->input(RuntimeAlignmentScopedInput::class)
        ->factory(
            'dynamic.singleton',
            static fn($container) => $container->get(RuntimeAlignmentScopedInput::class),
            LifetimeEnum::Singleton,
        );

    $runtime = $builder->build();

    expect(fn() => $runtime->withinScope(
        'request',
        static fn($active) => $active->get('dynamic.singleton'),
        [RuntimeAlignmentScopedInput::class => new RuntimeAlignmentScopedInput()],
    ))->toThrow(ContainerException::class, 'cannot capture scoped entry');
});
