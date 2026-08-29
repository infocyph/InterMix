<?php

declare(strict_types=1);

use Infocyph\InterMix\DI\ContainerBuilder;

final class StaticGetReturnSingleton
{
    public const string CALL_ON = 'boot';

    public function boot(): string
    {
        return 'singleton-return';
    }
}

final class StaticGetReturnScoped
{
    public const string CALL_ON = 'boot';

    public function boot(): string
    {
        return 'scoped-return';
    }
}

final class StaticGetReturnTransient
{
    public const string CALL_ON = 'boot';

    public function boot(): string
    {
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

it('preserves getReturn parity for singleton scoped and transient compiled classes', function () {
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
        $runtime = $builder->productionPrevalidated($path, $report['sha256']);
        $runtime->enterScope('request');

        expect($report['compiled'])->toContain('singleton', 'scoped', 'transient')
            ->and($developmentSingleton)->toBe('singleton-return')
            ->and($developmentScoped)->toBe('scoped-return')
            ->and($developmentTransient)->toBeInstanceOf(StaticGetReturnTransient::class)
            ->and($runtime->getReturn('singleton'))->toBe($developmentSingleton)
            ->and($runtime->getReturn('scoped'))->toBe($developmentScoped)
            ->and($runtime->getReturn('transient'))->toBeInstanceOf(StaticGetReturnTransient::class);

        $runtime->leaveScope();
    } finally {
        removeStaticGetReturnArtifact($path);
    }
});
