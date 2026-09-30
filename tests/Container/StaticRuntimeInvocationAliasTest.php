<?php

declare(strict_types=1);

use Infocyph\InterMix\DI\Build\DefinitionGraph;
use Infocyph\InterMix\DI\Build\StaticRuntimePlanner;
use Infocyph\InterMix\DI\Container;
use Infocyph\InterMix\DI\ContainerBuilder;
use Infocyph\InterMix\DI\Support\LifetimeEnum;
use Infocyph\InterMix\DI\Invoker\CompiledCall;
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
        $resolvedNow = $runtime->resolveNow(InvocationAliasRoot::class);

        expect($fresh)->toBeInstanceOf(InvocationAliasRoot::class)
            ->and($fresh)->not->toBe($shared)
            ->and($resolvedNow)->toBeInstanceOf(InvocationAliasRoot::class)
            ->and($resolvedNow)->not->toBe($shared)
            ->and($fresh->leaf)->toBe($runtime->get(InvocationAliasLeaf::class))
            ->and($resolvedNow->leaf)->toBe($runtime->get(InvocationAliasLeaf::class));
    } finally {
        removeInvocationAliasArtifact($path);
    }
});

it('keeps compiled getReturn and null resolveNow on the production boundary', function () {
    $builder = ContainerBuilder::create(uniqid('compiled_return_'));
    $builder->autowire(InvocationAliasRoot::class, InvocationAliasRoot::class)
        ->autowire(InvocationAliasLeaf::class, InvocationAliasLeaf::class);

    $path = invocationAliasArtifactPath();
    try {
        $builder->compile($path);
        $runtime = $builder->production($path);

        expect($runtime->getReturn(InvocationAliasRoot::class))->toBe($runtime->get(InvocationAliasRoot::class))
            ->and($runtime->resolveNow(null))->toBe($runtime);
    } finally {
        removeInvocationAliasArtifact($path);
    }
});

it('routes stale compiled definition dispatch through the dynamic resolver after invalidation', function () {
    $container = new Container(uniqid('stale_compiled_'));
    $container->singleton('service', InvocationAliasRoot::class)
        ->autowire(InvocationAliasLeaf::class, InvocationAliasLeaf::class);

    $path = invocationAliasArtifactPath();
    try {
        $container->compileTo($path, true);
        expect($container->getCurrentResolver())->toBeInstanceOf(CompiledCall::class)
            ->and($container->getRepository()->hasCompiledResolvers())->toBeTrue();

        $container->enableLazyLoading(false);

        expect($container->getRepository()->hasCompiledResolvers())->toBeFalse()
            ->and($container->get('service'))->toBeInstanceOf(InvocationAliasRoot::class);
    } finally {
        if (is_file($path)) {
            unlink($path);
        }
    }
});

it('does not let the empty property fast path hide later property registration', function () {
    $container = new Container(uniqid('property_fast_flag_'));
    $container->get(InvocationAliasLeaf::class);

    $container->registration()->registerProperty(
        InvocationAliasPropertyTarget::class,
        ['value' => 'registered'],
    );

    expect($container->get(InvocationAliasPropertyTarget::class)->value)->toBe('registered');
});
