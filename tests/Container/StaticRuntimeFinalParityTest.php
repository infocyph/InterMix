<?php

declare(strict_types=1);

use Infocyph\InterMix\DI\ContainerBuilder;

final class StaticFinalParityDependency {}
final readonly class StaticFinalParityRoot
{
    public function __construct(public StaticFinalParityDependency $dependency) {}
}
final class StaticFinalParityCallable
{
    public function ping(): string { return 'pong'; }
}

function staticFinalParityArtifactPath(): string
{
    return sys_get_temp_dir() . '/intermix-final-parity-' . bin2hex(random_bytes(8)) . '.php';
}
function removeStaticFinalParityArtifact(string $path): void
{
    foreach ([$path, $path . '.meta.json'] as $artifact) if (is_file($artifact)) unlink($artifact);
}

it('keeps native callable invocation identical across dynamic and production runtimes', function () {
    $builder = ContainerBuilder::create(uniqid('final_callable_'))
        ->autowire(StaticFinalParityDependency::class, StaticFinalParityDependency::class)
        ->autowire('callable', StaticFinalParityCallable::class);
    $path = staticFinalParityArtifactPath();

    try {
        $builder->compile($path);
        foreach ([$builder->build(), $builder->production($path)] as $runtime) {
            $root = $runtime->invoke(
                static fn(StaticFinalParityDependency $dependency): StaticFinalParityRoot =>
                    new StaticFinalParityRoot($dependency),
            );
            expect($root->dependency)->toBe($runtime->get(StaticFinalParityDependency::class))
                ->and($runtime->invoke([$runtime->get('callable'), 'ping']))->toBe('pong')
                ->and(method_exists($runtime, 'call'))->toBeFalse()
                ->and(method_exists($runtime, 'resolveNow'))->toBeFalse();
        }
    } finally {
        removeStaticFinalParityArtifact($path);
    }
});

it('keeps dynamic resolver machinery out of a fully static generated artifact', function () {
    $builder = ContainerBuilder::create(uniqid('final_artifact_'))
        ->autowire(StaticFinalParityDependency::class, StaticFinalParityDependency::class)
        ->autowire('root', StaticFinalParityRoot::class);
    $path = staticFinalParityArtifactPath();

    try {
        $report = $builder->compile($path);
        $source = file_get_contents($path);
        expect($report['compiled'])->toContain(StaticFinalParityDependency::class, 'root')
            ->and($source)->toBeString()
            ->and($source)->not->toContain('Reflection', 'Repository', 'RuntimeIslandResolver', 'ParameterResolver');
    } finally {
        removeStaticFinalParityArtifact($path);
    }
});
