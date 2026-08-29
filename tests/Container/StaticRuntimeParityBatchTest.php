<?php

declare(strict_types=1);

use Infocyph\InterMix\DI\Attribute\Inject;
use Infocyph\InterMix\DI\Build\DefinitionGraph;
use Infocyph\InterMix\DI\Build\StaticRuntimeGenerator;
use Infocyph\InterMix\DI\Container;
use Infocyph\InterMix\DI\ContainerBuilder;
use Infocyph\InterMix\Exceptions\ContainerException;

final class StaticBatchStableService {}

final class StaticBatchDynamicService {}

final class StaticBatchCallableService
{
    public function ping(): string
    {
        return 'pong';
    }
}

final class StaticBatchAttributedPropertyService
{
    #[Inject]
    public StaticBatchStableService $stable;
}

function staticBatchArtifactPath(): string
{
    return sys_get_temp_dir() . '/intermix-static-batch-' . bin2hex(random_bytes(8)) . '.php';
}

function removeStaticBatchArtifact(string $path): void
{
    foreach ([$path, $path . '.meta.json'] as $artifact) {
        if (is_file($artifact)) {
            unlink($artifact);
        }
    }
}

it('keeps lifecycle-hooked services in the dynamic island without deoptimizing neighbors', function () {
    $builder = ContainerBuilder::create(uniqid('static_batch_hooks_'));
    $resolved = 0;
    $builder->singleton('stable', StaticBatchStableService::class)
        ->singleton('hooked', StaticBatchDynamicService::class)
        ->onResolved('hooked', function () use (&$resolved): void {
            ++$resolved;
        });

    $path = staticBatchArtifactPath();
    try {
        $report = $builder->compile($path);
        $runtime = $builder->production($path);
        $stable = $runtime->get('stable');

        expect($report['compiled'])->toContain('stable')
            ->and($report['compiled'])->not->toContain('hooked')
            ->and($report['skipped']['hooked'])->toContain('lifecycle hooks')
            ->and($runtime->get('hooked'))->toBeInstanceOf(StaticBatchDynamicService::class)
            ->and($resolved)->toBe(1)
            ->and($runtime->get('stable'))->toBe($stable);
    } finally {
        removeStaticBatchArtifact($path);
    }
});

it('classifies only property-attributed services as dynamic when property attributes are enabled', function () {
    $builder = ContainerBuilder::create(uniqid('static_batch_property_'));
    $builder->singleton(StaticBatchStableService::class)
        ->singleton('attributed', StaticBatchAttributedPropertyService::class);
    $builder->options()->setOptions(propertyAttributes: true);

    $path = staticBatchArtifactPath();
    try {
        $report = $builder->compile($path);
        $runtime = $builder->production($path);
        $attributed = $runtime->get('attributed');

        expect($report['compiled'])->toContain(StaticBatchStableService::class)
            ->and($report['compiled'])->not->toContain('attributed')
            ->and($report['skipped']['attributed'])->toContain('runtime property attributes')
            ->and($attributed)->toBeInstanceOf(StaticBatchAttributedPropertyService::class)
            ->and($attributed->stable)->toBeInstanceOf(StaticBatchStableService::class);
    } finally {
        removeStaticBatchArtifact($path);
    }
});

it('uses the compiled service for calls to known definition ids', function () {
    $builder = ContainerBuilder::create(uniqid('static_batch_call_'));
    $builder->singleton('callable', StaticBatchCallableService::class);

    $path = staticBatchArtifactPath();
    try {
        $builder->compile($path);
        $runtime = $builder->production($path);
        $service = $runtime->get('callable');

        expect($runtime->call('callable'))->toBe($service)
            ->and($runtime->call('callable', 'ping'))->toBe('pong');
    } finally {
        removeStaticBatchArtifact($path);
    }
});

it('keeps direct factories and closure definitions as isolated dynamic services', function () {
    $builder = ContainerBuilder::create(uniqid('static_batch_dynamic_defs_'));
    $builder->singleton('stable', StaticBatchStableService::class)
        ->bindFactory('factory', static fn(Container $container): object => new StaticBatchDynamicService())
        ->bind('closure', static fn(): object => new StaticBatchDynamicService());

    $path = staticBatchArtifactPath();
    try {
        $report = $builder->compile($path);
        $runtime = $builder->production($path);
        $stable = $runtime->get('stable');

        expect($report['compiled'])->toContain('stable')
            ->and($report['compiled'])->not->toContain('factory', 'closure')
            ->and($runtime->get('factory'))->toBeInstanceOf(StaticBatchDynamicService::class)
            ->and($runtime->get('closure'))->toBeInstanceOf(StaticBatchDynamicService::class)
            ->and($runtime->get('stable'))->toBe($stable);
    } finally {
        removeStaticBatchArtifact($path);
    }
});

it('falls back for arbitrary autowireable classes without replacing compiled state', function () {
    $builder = ContainerBuilder::create(uniqid('static_batch_arbitrary_'));
    $builder->singleton('stable', StaticBatchStableService::class);

    $path = staticBatchArtifactPath();
    try {
        $builder->compile($path);
        $runtime = $builder->production($path);
        $stable = $runtime->get('stable');

        expect($runtime->get(StaticBatchDynamicService::class))->toBeInstanceOf(StaticBatchDynamicService::class)
            ->and($runtime->get('stable'))->toBe($stable);
    } finally {
        removeStaticBatchArtifact($path);
    }
});

it('validates the generated runtime against its metadata sidecar before loading', function () {
    $container = new Container(uniqid('static_batch_manifest_'));
    $container->singleton('stable', StaticBatchStableService::class);

    $path = staticBatchArtifactPath();
    try {
        $generator = new StaticRuntimeGenerator();
        $generator->generate(DefinitionGraph::from($container->getRepository()), $path);
        file_put_contents($path, "\n", FILE_APPEND);

        expect(fn() => $generator->load($path))->toThrow(
            ContainerException::class,
            'Static runtime artifact hash does not match its manifest.',
        );
    } finally {
        removeStaticBatchArtifact($path);
    }
});
