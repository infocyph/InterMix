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

it('keeps DI-style class recipes dynamic when injection is disabled', function () {
    $builder = ContainerBuilder::create(uniqid('static_generic_class_'));
    $builder->options()->setOptions(injection: false);
    $builder->singleton('consumer', StaticGenericConsumer::class);
    $builder->value('answer', 42);
    $path = staticGenericArtifactPath();

    try {
        $report = $builder->compile($path);
        $runtime = $builder->productionPrevalidated($path, $report['sha256']);

        expect($report['compiled'])->toContain('answer')
            ->and($report['compiled'])->not->toContain('consumer')
            ->and($report['skipped']['consumer'])->toContain('injection-off')
            ->and($runtime->get('answer'))->toBe(42)
            ->and(fn() => $runtime->get('consumer'))->toThrow(ContainerException::class);
    } finally {
        removeStaticGenericArtifact($path);
    }
});
