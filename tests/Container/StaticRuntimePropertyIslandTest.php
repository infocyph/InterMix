<?php

declare(strict_types=1);

use Infocyph\InterMix\DI\ContainerBuilder;

final class RuntimePropertyIslandPayload {}

final class RuntimePropertyIslandConsumer
{
    public mixed $payload = null;
}

function runtimePropertyIslandArtifactPath(): string
{
    return sys_get_temp_dir() . '/intermix-property-island-' . bin2hex(random_bytes(8)) . '.php';
}

function removeRuntimePropertyIslandArtifact(string $path): void
{
    foreach ([$path, $path . '.meta.json'] as $artifact) {
        if (is_file($artifact)) {
            unlink($artifact);
        }
    }
}

it('keeps non-exportable registered property values in a targeted runtime island', function () {
    $payload = new RuntimePropertyIslandPayload();
    $builder = ContainerBuilder::create(uniqid('runtime_property_island_'));
    $builder->singleton(RuntimePropertyIslandConsumer::class);
    $builder->registration()->registerProperty(
        RuntimePropertyIslandConsumer::class,
        ['payload' => $payload],
    );
    $path = runtimePropertyIslandArtifactPath();

    try {
        $report = $builder->compile($path);
        $source = file_get_contents($path);
        $runtime = $builder->productionPrevalidated($path, $report['digest']);
        $consumer = $runtime->get(RuntimePropertyIslandConsumer::class);

        expect($report['compiled'])->toContain(RuntimePropertyIslandConsumer::class)
            ->and($source)->toContain('applyCompiledRuntimePropertyAttribute')
            ->and($consumer->payload)->toBe($payload);
    } finally {
        removeRuntimePropertyIslandArtifact($path);
    }
});
