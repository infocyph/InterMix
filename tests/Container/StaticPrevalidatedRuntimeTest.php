<?php

declare(strict_types=1);

use Infocyph\InterMix\DI\ContainerBuilder;
use Infocyph\InterMix\Exceptions\ContainerException;

final class StaticPrevalidatedService {}

function staticPrevalidatedArtifactPath(): string
{
    return sys_get_temp_dir() . '/intermix-static-prevalidated-' . bin2hex(random_bytes(8)) . '.php';
}

function removeStaticPrevalidatedArtifact(string $path): void
{
    foreach ([$path, $path . '.meta.json'] as $artifact) {
        if (is_file($artifact)) {
            unlink($artifact);
        }
    }
}

it('loads a production runtime from the deployment digest returned by compile', function () {
    $builder = ContainerBuilder::create(uniqid('static_prevalidated_'));
    $builder->singleton('service', StaticPrevalidatedService::class);
    $path = staticPrevalidatedArtifactPath();

    try {
        $report = $builder->compile($path);
        $runtime = $builder->productionPrevalidated($path, $report['digest']);

        expect($report['digest'])->toMatch('/^[a-f0-9]{32}$/')
            ->and($runtime->get('service'))->toBeInstanceOf(StaticPrevalidatedService::class)
            ->and($runtime->get('service'))->toBe($runtime->get('service'));
    } finally {
        removeStaticPrevalidatedArtifact($path);
    }
});

it('rejects malformed and mismatched deployment digests', function () {
    $builder = ContainerBuilder::create(uniqid('static_prevalidated_invalid_'));
    $builder->singleton('service', StaticPrevalidatedService::class);
    $path = staticPrevalidatedArtifactPath();

    try {
        $report = $builder->compile($path);

        expect(fn() => $builder->productionPrevalidated($path, 'invalid'))
            ->toThrow(ContainerException::class, 'xxh128')
            ->and(fn() => $builder->productionPrevalidated($path, str_repeat('0', 32)))
            ->toThrow(ContainerException::class, 'deployment digest')
            ->and($report['digest'])->not->toBe(str_repeat('0', 32));
    } finally {
        removeStaticPrevalidatedArtifact($path);
    }
});

it('requires recompilation after environment mutation', function () {
    $builder = ContainerBuilder::create(uniqid('static_prevalidated_environment_'));
    $builder->setEnvironment('production')
        ->singleton('service', StaticPrevalidatedService::class);
    $path = staticPrevalidatedArtifactPath();

    try {
        $report = $builder->compile($path);
        $builder->setEnvironment('staging');

        expect(fn() => $builder->production($path))
            ->toThrow(ContainerException::class, 'recompiled')
            ->and(fn() => $builder->productionPrevalidated($path, $report['digest']))
            ->toThrow(ContainerException::class, 'recompiled');
    } finally {
        removeStaticPrevalidatedArtifact($path);
    }
});

it('validates the artifact environment when loading in a fresh process builder', function () {
    $compiler = ContainerBuilder::create(uniqid('static_prevalidated_environment_source_'));
    $compiler->setEnvironment('production')
        ->singleton('service', StaticPrevalidatedService::class);
    $path = staticPrevalidatedArtifactPath();

    try {
        $report = $compiler->compile($path);
        $loader = ContainerBuilder::create(uniqid('static_prevalidated_environment_loader_'));
        $loader->setEnvironment('staging')
            ->singleton('service', StaticPrevalidatedService::class);

        expect(fn() => $loader->production($path))
            ->toThrow(ContainerException::class, 'environment')
            ->and(fn() => $loader->productionPrevalidated($path, $report['digest']))
            ->toThrow(ContainerException::class, 'environment');
    } finally {
        removeStaticPrevalidatedArtifact($path);
    }
});
