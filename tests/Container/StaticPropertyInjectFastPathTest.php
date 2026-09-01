<?php

declare(strict_types=1);

use Infocyph\InterMix\DI\Attribute\Inject;
use Infocyph\InterMix\DI\ContainerBuilder;

final class StaticPrivateInjectDependency {}

final class StaticPrivateInjectConsumer
{
    #[Inject]
    private StaticPrivateInjectDependency $dependency;

    public function dependency(): StaticPrivateInjectDependency
    {
        return $this->dependency;
    }
}

final class StaticReadonlyInjectConsumer
{
    #[Inject]
    private readonly StaticPrivateInjectDependency $dependency;

    public function dependency(): StaticPrivateInjectDependency
    {
        return $this->dependency;
    }
}

function staticPropertyInjectArtifactPath(): string
{
    return sys_get_temp_dir() . '/intermix-property-inject-' . bin2hex(random_bytes(8)) . '.php';
}

function removeStaticPropertyInjectArtifact(string $path): void
{
    foreach ([$path, $path . '.meta.json'] as $artifact) {
        if (is_file($artifact)) {
            unlink($artifact);
        }
    }
}

it('keeps compile-known private Inject dependencies out of attribute resolution islands', function () {
    $builder = ContainerBuilder::create(uniqid('private_property_inject_'));
    $builder->options()->setOptions(propertyAttributes: true);
    $builder->singleton(StaticPrivateInjectDependency::class)
        ->singleton(StaticPrivateInjectConsumer::class)
        ->singleton(StaticReadonlyInjectConsumer::class);
    $path = staticPropertyInjectArtifactPath();

    try {
        $report = $builder->compile($path);
        $source = file_get_contents($path);
        $runtime = $builder->productionPrevalidated($path, $report['digest']);
        $dependency = $runtime->get(StaticPrivateInjectDependency::class);
        $private = $runtime->get(StaticPrivateInjectConsumer::class);
        $readonly = $runtime->get(StaticReadonlyInjectConsumer::class);

        expect($report['compiled'])->toContain(
            StaticPrivateInjectDependency::class,
            StaticPrivateInjectConsumer::class,
            StaticReadonlyInjectConsumer::class,
        )
            ->and($source)->toBeString()
            ->toContain('assignCompiledRuntimeProperty(')
            ->not->toContain('applyCompiledRuntimePropertyAttribute(')
            ->and($private->dependency())->toBe($dependency)
            ->and($readonly->dependency())->toBe($dependency);
    } finally {
        removeStaticPropertyInjectArtifact($path);
    }
});
