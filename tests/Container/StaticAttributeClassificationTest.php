<?php

declare(strict_types=1);

use Infocyph\InterMix\DI\Attribute\AttributeResolverInterface;
use Infocyph\InterMix\DI\Container;
use Infocyph\InterMix\DI\ContainerBuilder;
use Reflector;

#[Attribute(Attribute::TARGET_PARAMETER)]
final class StaticIgnoredParameterAttribute {}

#[Attribute(Attribute::TARGET_PARAMETER)]
final class StaticRuntimeParameterAttribute {}

final class StaticRuntimeParameterAttributeResolver implements AttributeResolverInterface
{
    public function resolve(object $attributeInstance, Reflector $target, Container $container): mixed
    {
        return 'runtime';
    }
}

final class StaticAttributeDependency {}

final class StaticIgnoredAttributeConsumer
{
    public const string CALL_ON = 'boot';

    public ?StaticAttributeDependency $dependency = null;

    public function boot(#[StaticIgnoredParameterAttribute] StaticAttributeDependency $dependency): void
    {
        $this->dependency = $dependency;
    }
}

final class StaticRuntimeAttributeConsumer
{
    public const string CALL_ON = 'boot';

    public string $value = 'initial';

    public function boot(#[StaticRuntimeParameterAttribute] string $value = 'fallback'): void
    {
        $this->value = $value;
    }
}

function staticAttributeArtifactPath(): string
{
    return sys_get_temp_dir() . '/intermix-static-attribute-' . bin2hex(random_bytes(8)) . '.php';
}

function removeStaticAttributeArtifact(string $path): void
{
    foreach ([$path, $path . '.meta.json'] as $artifact) {
        if (is_file($artifact)) {
            unlink($artifact);
        }
    }
}

it('does not deoptimize methods for unregistered parameter attributes', function () {
    $builder = ContainerBuilder::create(uniqid('static_ignored_attribute_'));
    $builder->options()->setOptions(methodAttributes: true);
    $builder->singleton('consumer', StaticIgnoredAttributeConsumer::class);
    $path = staticAttributeArtifactPath();

    try {
        $report = $builder->compile($path);
        $consumer = $builder->productionPrevalidated($path, $report['sha256'])->get('consumer');

        expect($report['compiled'])->toContain('consumer', StaticAttributeDependency::class)
            ->and($consumer->dependency)->toBeInstanceOf(StaticAttributeDependency::class);
    } finally {
        removeStaticAttributeArtifact($path);
    }
});

it('keeps registered custom parameter attribute resolvers in the dynamic island', function () {
    $builder = ContainerBuilder::create(uniqid('static_runtime_attribute_'));
    $builder->options()->registerAttributeResolver(
        StaticRuntimeParameterAttribute::class,
        StaticRuntimeParameterAttributeResolver::class,
    );
    $builder->options()->setOptions(methodAttributes: true);
    $builder->singleton('consumer', StaticRuntimeAttributeConsumer::class);
    $path = staticAttributeArtifactPath();

    try {
        $report = $builder->compile($path);
        $consumer = $builder->productionPrevalidated($path, $report['sha256'])->get('consumer');

        expect($report['compiled'])->not->toContain('consumer')
            ->and($report['skipped']['consumer'])->toContain('runtime attributes')
            ->and($consumer->value)->toBe('runtime');
    } finally {
        removeStaticAttributeArtifact($path);
    }
});
