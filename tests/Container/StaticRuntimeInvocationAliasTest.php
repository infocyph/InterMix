<?php

declare(strict_types=1);

use Infocyph\InterMix\DI\Build\StaticRuntimePlanner;
use Infocyph\InterMix\DI\ContainerBuilder;
use Infocyph\InterMix\DI\Support\LifetimeEnum;
use Infocyph\InterMix\Exceptions\ContainerException;

final class InvocationAliasLeaf {}

final readonly class InvocationAliasRoot
{
    public function __construct(public InvocationAliasLeaf $leaf) {}
}

final class InvocationAliasPropertyTarget
{
    public string $value = 'unset';
}

function invocationAliasArtifactPath(): string
{
    return sys_get_temp_dir() . '/intermix-invocation-alias-' . bin2hex(random_bytes(8)) . '.php';
}

function removeInvocationAliasArtifact(string $path): void
{
    foreach ([$path, $path . '.meta.json'] as $artifact) {
        if (is_file($artifact)) {
            unlink($artifact);
        }
    }
}

it('makes aliases follow target lifetime without owning a cache', function () {
    $builder = ContainerBuilder::create(uniqid('alias_target_lifetime_'));
    $builder->autowire('target', InvocationAliasLeaf::class, lifetime: LifetimeEnum::Transient)
        ->alias('middle', 'target')
        ->alias('root', 'middle');

    $development = $builder->build();
    expect($development->get('root'))->not->toBe($development->get('root'));

    $path = invocationAliasArtifactPath();
    try {
        $report = $builder->compile($path);
        $runtime = $builder->production($path);

        expect($report['compiled'])->toContain('target', 'middle', 'root')
            ->and($runtime->get('root'))->not->toBe($runtime->get('root'))
            ->and($runtime->get('middle'))->not->toBe($runtime->get('target'));
    } finally {
        removeInvocationAliasArtifact($path);
    }
});

it('flattens pure transient alias chains to their final build-time target', function () {
    $builder = ContainerBuilder::create(uniqid('alias_flatten_'));
    $builder->autowire('target', InvocationAliasLeaf::class)
        ->alias('middle', 'target')
        ->alias('root', 'middle');

    $planned = new StaticRuntimePlanner()->plan(
        $builder->definitionGraph(),
    );

    expect($planned['plans']['root']['kind'])->toBe('alias')
        ->and($planned['plans']['root']['target'])->toBe('target')
        ->and($planned['plans']['root']['dependencies'])->toBe(['target']);
});

it('rejects alias cycles before artifact publication', function () {
    $builder = ContainerBuilder::create(uniqid('alias_cycle_'));
    $builder->alias('a', 'b')
        ->alias('b', 'c')
        ->alias('c', 'a');

    $path = invocationAliasArtifactPath();
    try {
        expect(fn() => $builder->compile($path))
            ->toThrow(ContainerException::class, "Alias 'a' participates in a cycle.")
            ->and(is_file($path))->toBeFalse();
    } finally {
        removeInvocationAliasArtifact($path);
    }
});

it('makes fresh compiled classes while retaining compiled dependency lifetimes', function () {
    $builder = ContainerBuilder::create(uniqid('compiled_make_'));
    $builder->autowire(InvocationAliasLeaf::class, InvocationAliasLeaf::class)
        ->autowire(InvocationAliasRoot::class, InvocationAliasRoot::class);

    $path = invocationAliasArtifactPath();
    try {
        $builder->compile($path);
        $runtime = $builder->production($path);
        $shared = $runtime->get(InvocationAliasRoot::class);
        $fresh = $runtime->make(InvocationAliasRoot::class);
        $secondFresh = $runtime->make(InvocationAliasRoot::class);

        expect($fresh)->toBeInstanceOf(InvocationAliasRoot::class)
            ->and($fresh)->not->toBe($shared)
            ->and($secondFresh)->toBeInstanceOf(InvocationAliasRoot::class)
            ->and($secondFresh)->not->toBe($shared)
            ->and($fresh->leaf)->toBe($runtime->get(InvocationAliasLeaf::class))
            ->and($secondFresh->leaf)->toBe($runtime->get(InvocationAliasLeaf::class));
    } finally {
        removeInvocationAliasArtifact($path);
    }
});

it('exposes only canonical retrieval and construction methods', function () {
    $builder = ContainerBuilder::create(uniqid('compiled_boundary_'))
        ->autowire(InvocationAliasRoot::class, InvocationAliasRoot::class)
        ->autowire(InvocationAliasLeaf::class, InvocationAliasLeaf::class);
    $path = invocationAliasArtifactPath();

    try {
        $builder->compile($path);
        $runtime = $builder->production($path);
        expect($runtime->get(InvocationAliasRoot::class))->toBe($runtime->get(InvocationAliasRoot::class))
            ->and($runtime->make(InvocationAliasRoot::class))->not->toBe($runtime->get(InvocationAliasRoot::class))
            ->and(method_exists($runtime, 'getReturn'))->toBeFalse()
            ->and(method_exists($runtime, 'resolveNow'))->toBeFalse();
    } finally {
        removeInvocationAliasArtifact($path);
    }
});
it('keeps compiled definition dispatch frozen after builder finalization', function () {
    $builder = ContainerBuilder::create(uniqid('frozen_compiled_'))
        ->autowire(InvocationAliasLeaf::class, InvocationAliasLeaf::class)
        ->autowire('service', InvocationAliasRoot::class);

    $path = invocationAliasArtifactPath();
    try {
        $report = $builder->compile($path);
        $runtime = $builder->productionPrevalidated($path, $report['digest']);
        $service = $runtime->get('service');

        expect($report['compiled'])->toContain('service', InvocationAliasLeaf::class)
            ->and($service)->toBeInstanceOf(InvocationAliasRoot::class)
            ->and(fn() => $builder->enableLazyLoading(false))
            ->toThrow(ContainerException::class, 'ContainerBuilder is finalized')
            ->and($runtime->get('service'))->toBe($service);
    } finally {
        removeInvocationAliasArtifact($path);
    }
});

it('applies property metadata before finalization and freezes later mutation', function () {
    $builder = ContainerBuilder::create(uniqid('property_fast_flag_'))
        ->autowire(
            'target',
            InvocationAliasPropertyTarget::class,
            properties: ['value' => 'registered'],
        );

    $runtime = $builder->build();

    expect($runtime->get('target')->value)->toBe('registered')
        ->and(fn() => $builder->value('late', true))->toThrow(ContainerException::class, 'ContainerBuilder is finalized')
        ->and($runtime->get('target')->value)->toBe('registered');
});
