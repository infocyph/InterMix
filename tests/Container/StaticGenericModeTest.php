<?php

declare(strict_types=1);

use Infocyph\InterMix\DI\ContainerBuilder;

final class StaticGenericEmptyService {}

final readonly class StaticGenericOptionalService
{
    public function __construct(public int $value = 7) {}
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

test('the public injection-disabled mode is removed', function () {
    expect(method_exists(ContainerBuilder::class, 'enableInjection'))->toBeFalse();
});

test('ordinary PHP invocation remains available when injection is unwanted', function () {
    $callable = static fn(string $value): string => strtoupper($value);

    expect($callable('direct'))->toBe('DIRECT');
});

test('compiled runtimes retain ordinary explicit autowire recipes', function () {
    $builder = ContainerBuilder::create()
        ->autowire('empty', StaticGenericEmptyService::class)
        ->autowire('optional', StaticGenericOptionalService::class);
    $path = staticGenericArtifactPath();

    try {
        $report = $builder->compile($path);
        $runtime = $builder->productionPrevalidated($path, $report['digest']);

        expect($report['compiled'])->toContain('empty', 'optional')
            ->and($runtime->get('empty'))->toBeInstanceOf(StaticGenericEmptyService::class)
            ->and($runtime->get('optional')->value)->toBe(7);
    } finally {
        removeStaticGenericArtifact($path);
    }
});
