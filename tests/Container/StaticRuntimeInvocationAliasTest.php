<?php

declare(strict_types=1);

use Infocyph\InterMix\DI\Build\DefinitionGraph;
use Infocyph\InterMix\DI\Build\StaticRuntimePlanner;
use Infocyph\InterMix\DI\Container;
use Infocyph\InterMix\DI\ContainerBuilder;
use Infocyph\InterMix\DI\Invoker\CompiledCall;
use Infocyph\InterMix\DI\Support\LifetimeEnum;

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

it('preserves alias lifetime barriers while flattening transient alias links', function () {
    $builder = ContainerBuilder::create(uniqid('alias_barrier_'));
    $builder->transient('target', InvocationAliasLeaf::class)
        ->alias('cached', 'target', LifetimeEnum::Singleton)
        ->alias('root', 'cached', LifetimeEnum::Transient);

    $development = $builder->development();
    $developmentRoot = $development->get('root');
    expect($developmentRoot)->toBe($development->get('cached'))
        ->and($development->get('root'))->toBe($developmentRoot);

    $path = invocationAliasArtifactPath();
    try {
        $report = $builder->compile($path);
        $runtime = $builder->production($path);
        $root = $runtime->get('root');

        expect($report['compiled'])->toContain('target', 'cached', 'root')
            ->and($root)->toBe($runtime->get('cached'))
            ->and($runtime->get('root'))->toBe($root)
            ->and($runtime->get('target'))->not->toBe($root);
    } finally {
        removeInvocationAliasArtifact($path);
    }
});

it('flattens pure transient alias chains to their final build-time target', function () {
    $builder = ContainerBuilder::create(uniqid('alias_flatten_'));
    $builder->singleton('target', InvocationAliasLeaf::class)
        ->alias('middle', 'target', LifetimeEnum::Transient)
        ->alias('root', 'middle', LifetimeEnum::Transient);

    $planned = new StaticRuntimePlanner()->plan(
        DefinitionGraph::from($builder->development()->getRepository()),
    );

    expect($planned['plans']['root']['kind'])->toBe('alias')
        ->and($planned['plans']['root']['target'])->toBe('target')
        ->and($planned['plans']['root']['dependencies'])->toBe(['target']);
});

it('rejects alias cycles during static planning', function () {
    $builder = ContainerBuilder::create(uniqid('alias_cycle_'));
    $builder->alias('a', 'b')
        ->alias('b', 'c')
        ->alias('c', 'a');

    $path = invocationAliasArtifactPath();
    try {
        $report = $builder->compile($path);

        expect($report['compiled'])->not->toContain('a', 'b', 'c')
            ->and($report['skipped']['a'])->toBe('alias graph contains a cycle')
            ->and($report['skipped']['b'])->toBe('alias graph contains a cycle')
            ->and($report['skipped']['c'])->toBe('alias graph contains a cycle');
    } finally {
        removeInvocationAliasArtifact($path);
    }
});

it('makes fresh compiled classes while retaining compiled dependency lifetimes', function () {
    $builder = ContainerBuilder::create(uniqid('compiled_make_'));
    $builder->singleton(InvocationAliasLeaf::class)
        ->singleton(InvocationAliasRoot::class);

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
    $builder->singleton(InvocationAliasRoot::class)
        ->singleton(InvocationAliasLeaf::class);

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
        ->singleton(InvocationAliasLeaf::class);

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
