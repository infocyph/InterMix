<?php

declare(strict_types=1);

use Infocyph\InterMix\DI\Build\DefinitionGraph;
use Infocyph\InterMix\DI\ContainerBuilder;
use Infocyph\InterMix\Exceptions\ContainerException;

final class StaticGenericDependency {}

final readonly class StaticGenericConsumer
{
    public function __construct(public StaticGenericDependency $dependency) {}
}

final class StaticGenericEmptyService {}

final readonly class StaticGenericOptionalService
{
    public function __construct(public int $value = 7) {}
}

final readonly class StaticGenericConfiguredService
{
    public function __construct(public int $value) {}
}

function staticGenericArtifactPath(): string
{
    return sys_get_temp_dir() . '/intermix-static-generic-' . bin2hex(random_bytes(8)) . '.php';
}

function removeStaticGenericArtifact(string $path): void
{
    foreach ([$path, $path . '.meta.json'] as $artifact) {
        if (is_file($artifact)) {
            unlink($artifact);
        }
    }
}

it('captures injection-off mode in the immutable definition graph', function () {
    $builder = ContainerBuilder::create(uniqid('static_generic_graph_'));
    $builder->options()->setOptions(injection: false);

    $graph = DefinitionGraph::from($builder->development()->getRepository());

    expect($graph->injectionEnabled())->toBeFalse();
});

it('compiles zero-required-argument generic classes without autowiring machinery', function () {
    $builder = ContainerBuilder::create(uniqid('static_generic_compiled_'));
    $builder->options()->setOptions(injection: false);
    $builder->singleton('empty', StaticGenericEmptyService::class);
    $builder->singleton('optional', StaticGenericOptionalService::class);
    $path = staticGenericArtifactPath();

    try {
        $report = $builder->compile($path);
        $runtime = $builder->productionPrevalidated($path, $report['digest']);
        $first = $runtime->get('empty');
        $second = $runtime->get('empty');
        $optional = $runtime->get('optional');

        expect($report['compiled'])->toContain('empty', 'optional')
            ->and($first)->toBeInstanceOf(StaticGenericEmptyService::class)
            ->and($second)->toBe($first)
            ->and($optional)->toBeInstanceOf(StaticGenericOptionalService::class)
            ->and($optional->value)->toBe(7);
    } finally {
        removeStaticGenericArtifact($path);
    }
});

it('keeps registered generic constructor behavior in the dynamic island', function () {
    $builder = ContainerBuilder::create(uniqid('static_generic_configured_'));
    $builder->options()->setOptions(injection: false);
    $builder->registration()->registerClass(StaticGenericConfiguredService::class, ['value' => 9]);
    $builder->singleton('configured', StaticGenericConfiguredService::class);
    $path = staticGenericArtifactPath();

    try {
        $report = $builder->compile($path);
        $runtime = $builder->productionPrevalidated($path, $report['digest']);
        $configured = $runtime->get('configured');

        expect($report['compiled'])->not->toContain('configured')
            ->and($report['skipped']['configured'])->toContain('registered generic resources')
            ->and($configured)->toBeInstanceOf(StaticGenericConfiguredService::class)
            ->and($configured->value)->toBe(9);
    } finally {
        removeStaticGenericArtifact($path);
    }
});

it('keeps DI-style class recipes dynamic when injection is disabled', function () {
    $builder = ContainerBuilder::create(uniqid('static_generic_class_'));
    $builder->options()->setOptions(injection: false);
    $builder->singleton('consumer', StaticGenericConsumer::class);
    $builder->value('answer', 42);
    $path = staticGenericArtifactPath();

    try {
        $report = $builder->compile($path);
        $runtime = $builder->productionPrevalidated($path, $report['digest']);

        expect($report['compiled'])->toContain('answer')
            ->and($report['compiled'])->not->toContain('consumer')
            ->and($report['skipped']['consumer'])->toContain('constructor parameters')
            ->and($runtime->get('answer'))->toBe(42)
            ->and(fn() => $runtime->get('consumer'))->toThrow(ContainerException::class);
    } finally {
        removeStaticGenericArtifact($path);
    }
});
