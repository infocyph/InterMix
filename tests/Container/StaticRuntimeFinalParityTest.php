<?php

declare(strict_types=1);

use Infocyph\InterMix\DI\ContainerBuilder;
use Infocyph\InterMix\Exceptions\ContainerException;

final class StaticFinalParityDependency {}

final readonly class StaticFinalParityRoot
{
    public function __construct(public StaticFinalParityDependency $dependency) {}
}

final class StaticFinalParityCallable
{
    public function ping(): string
    {
        return 'pong';
    }
}

function staticFinalParityArtifactPath(): string
{
    return sys_get_temp_dir() . '/intermix-final-parity-' . bin2hex(random_bytes(8)) . '.php';
}

function removeStaticFinalParityArtifact(string $path): void
{
    foreach ([$path, $path . '.meta.json'] as $artifact) {
        if (is_file($artifact)) {
            unlink($artifact);
        }
    }
}

it('preserves compiled call error semantics for missing methods', function () {
    $developmentBuilder = ContainerBuilder::create(uniqid('final_dev_call_'));
    $developmentBuilder->singleton('callable', StaticFinalParityCallable::class);

    $developmentError = null;
    try {
        $developmentBuilder->development()->call('callable', 'missing');
    } catch (ContainerException $exception) {
        $developmentError = $exception;
    }

    $productionBuilder = ContainerBuilder::create(uniqid('final_prod_call_'));
    $productionBuilder->singleton('callable', StaticFinalParityCallable::class);
    $path = staticFinalParityArtifactPath();

    try {
        $report = $productionBuilder->compile($path);
        $runtime = $productionBuilder->productionPrevalidated($path, $report['sha256']);

        expect($developmentError)->toBeInstanceOf(ContainerException::class)
            ->and(fn() => $runtime->call('callable', 'missing'))->toThrow(
                ContainerException::class,
                $developmentError->getMessage(),
            );
    } finally {
        removeStaticFinalParityArtifact($path);
    }
});

it('keeps arbitrary closure invocation in a narrow dynamic island with compiled dependency identity', function () {
    $builder = ContainerBuilder::create(uniqid('final_closure_'));
    $builder->singleton(StaticFinalParityDependency::class);
    $path = staticFinalParityArtifactPath();

    try {
        $report = $builder->compile($path);
        $runtime = $builder->productionPrevalidated($path, $report['sha256']);
        $consumer = $runtime->resolveNow(
            static fn(StaticFinalParityDependency $dependency): StaticFinalParityRoot => new StaticFinalParityRoot($dependency),
        );

        expect($consumer)->toBeInstanceOf(StaticFinalParityRoot::class)
            ->and($consumer->dependency)->toBe($runtime->get(StaticFinalParityDependency::class));
    } finally {
        removeStaticFinalParityArtifact($path);
    }
});

it('preserves callable parser errors across development and production fallback', function () {
    $developmentBuilder = ContainerBuilder::create(uniqid('final_dev_parser_'));
    $developmentError = null;
    try {
        $developmentBuilder->development()->resolveNow(['StaticFinalParityMissingClass', 'run']);
    } catch (ContainerException $exception) {
        $developmentError = $exception;
    }

    $productionBuilder = ContainerBuilder::create(uniqid('final_prod_parser_'));
    $productionBuilder->singleton(StaticFinalParityDependency::class);
    $path = staticFinalParityArtifactPath();

    try {
        $report = $productionBuilder->compile($path);
        $runtime = $productionBuilder->productionPrevalidated($path, $report['sha256']);

        expect($developmentError)->toBeInstanceOf(ContainerException::class)
            ->and(fn() => $runtime->resolveNow(['StaticFinalParityMissingClass', 'run']))->toThrow(
                ContainerException::class,
                $developmentError->getMessage(),
            );
    } finally {
        removeStaticFinalParityArtifact($path);
    }
});

it('keeps dynamic resolver machinery out of a fully static generated artifact', function () {
    $builder = ContainerBuilder::create(uniqid('final_artifact_'));
    $builder->singleton(StaticFinalParityDependency::class)
        ->singleton('root', StaticFinalParityRoot::class);
    $path = staticFinalParityArtifactPath();

    try {
        $report = $builder->compile($path);
        $source = file_get_contents($path);

        expect($report['compiled'])->toContain(StaticFinalParityDependency::class, 'root')
            ->and($source)->toBeString()
            ->and($source)->not->toContain(
                'Reflection',
                'Repository',
                'RuntimeIslandResolver',
                'ParameterResolver',
                'ClassResolution',
                'InjectedCall',
            );
    } finally {
        removeStaticFinalParityArtifact($path);
    }
});
