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
        $runtime = $builder->productionPrevalidated($path, $report['sha256']);

        expect($report['sha256'])->toMatch('/^[a-f0-9]{64}$/')
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
            ->toThrow(ContainerException::class, 'SHA-256')
            ->and(fn() => $builder->productionPrevalidated($path, str_repeat('0', 64)))
            ->toThrow(ContainerException::class, 'deployment digest')
            ->and($report['sha256'])->not->toBe(str_repeat('0', 64));
    } finally {
        removeStaticPrevalidatedArtifact($path);
    }
});
