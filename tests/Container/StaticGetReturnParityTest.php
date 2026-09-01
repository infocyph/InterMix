<?php

declare(strict_types=1);

use Infocyph\InterMix\DI\ContainerBuilder;

final class StaticGetReturnSingleton
{
    public const string CALL_ON = 'boot';

    public bool $booted = false;

    public function boot(): string
    {
        $this->booted = true;

        return 'singleton-return';
    }
}

final class StaticGetReturnScoped
{
    public const string CALL_ON = 'boot';

    public bool $booted = false;

    public function boot(): string
    {
        $this->booted = true;

        return 'scoped-return';
    }
}

final class StaticGetReturnTransient
{
    public const string CALL_ON = 'boot';

    public bool $booted = false;

    public function boot(): string
    {
        $this->booted = true;

        return 'transient-return';
    }
}

function staticGetReturnArtifactPath(): string
{
    return sys_get_temp_dir() . '/intermix-get-return-' . bin2hex(random_bytes(8)) . '.php';
}

function removeStaticGetReturnArtifact(string $path): void
{
    foreach ([$path, $path . '.meta.json'] as $artifact) {
        if (is_file($artifact)) {
            unlink($artifact);
        }
    }
}

it('preserves registered getReturn semantics while still invoking configured methods', function () {
    $builder = ContainerBuilder::create(uniqid('get_return_'));
    $builder->singleton('singleton', StaticGetReturnSingleton::class)
        ->scoped('scoped', StaticGetReturnScoped::class)
        ->transient('transient', StaticGetReturnTransient::class);

    $development = $builder->development();
    $development->enterScope('request');
    $developmentSingleton = $development->getReturn('singleton');
    $developmentScoped = $development->getReturn('scoped');
    $developmentTransient = $development->getReturn('transient');
    $development->leaveScope();

    $path = staticGetReturnArtifactPath();
    try {
        $report = $builder->compile($path);
        $runtime = $builder->productionPrevalidated($path, $report['digest']);
        $runtime->enterScope('request');
        $productionSingleton = $runtime->getReturn('singleton');
        $productionScoped = $runtime->getReturn('scoped');
        $productionTransient = $runtime->getReturn('transient');

        expect($report['compiled'])->toContain('singleton', 'scoped', 'transient')
            ->and($developmentSingleton)->toBeInstanceOf(StaticGetReturnSingleton::class)
            ->and($developmentScoped)->toBeInstanceOf(StaticGetReturnScoped::class)
            ->and($developmentTransient)->toBeInstanceOf(StaticGetReturnTransient::class)
            ->and($developmentSingleton->booted)->toBeTrue()
            ->and($developmentScoped->booted)->toBeTrue()
            ->and($developmentTransient->booted)->toBeTrue()
            ->and($productionSingleton)->toBeInstanceOf(StaticGetReturnSingleton::class)
            ->and($productionScoped)->toBeInstanceOf(StaticGetReturnScoped::class)
            ->and($productionTransient)->toBeInstanceOf(StaticGetReturnTransient::class)
            ->and($productionSingleton->booted)->toBeTrue()
            ->and($productionScoped->booted)->toBeTrue()
            ->and($productionTransient->booted)->toBeTrue()
            ->and($runtime->getReturn('singleton'))->toBe($productionSingleton)
            ->and($runtime->getReturn('scoped'))->toBe($productionScoped);

        $runtime->leaveScope();
    } finally {
        removeStaticGetReturnArtifact($path);
    }
});
