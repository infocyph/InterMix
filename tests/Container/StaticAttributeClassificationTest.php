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

#[Attribute(Attribute::TARGET_PROPERTY)]
final class StaticRuntimePropertyAttribute {}

final class StaticRuntimeParameterAttributeResolver implements AttributeResolverInterface
{
    public function resolve(object $attributeInstance, Reflector $target, Container $container): mixed
    {
        return 'runtime';
    }
}

final class StaticRuntimePropertyAttributeResolver implements AttributeResolverInterface
{
    public function resolve(object $attributeInstance, Reflector $target, Container $container): mixed
    {
        return 'runtime-property';
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

final readonly class StaticConstructorAttributeConsumer
{
    public function __construct(
        #[StaticRuntimeParameterAttribute]
        public string $value = 'fallback',
    ) {}
}

final class StaticRuntimePropertyConsumer
{
    #[StaticRuntimePropertyAttribute]
    public string $value = 'initial';
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

it('keeps registered custom method attribute resolvers as targeted runtime islands', function () {
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
        $source = file_get_contents($path);
        $consumer = $builder->productionPrevalidated($path, $report['sha256'])->get('consumer');

        expect($report['compiled'])->toContain('consumer')
            ->and($source)->toContain('invokeCompiledRuntimeMethod')
            ->and($consumer->value)->toBe('runtime');
    } finally {
        removeStaticAttributeArtifact($path);
    }
});

it('preserves development semantics by ignoring method attributes on constructors', function () {
    $builder = ContainerBuilder::create(uniqid('static_constructor_attribute_'));
    $builder->options()->registerAttributeResolver(
        StaticRuntimeParameterAttribute::class,
        StaticRuntimeParameterAttributeResolver::class,
    );
    $builder->options()->setOptions(methodAttributes: true);
    $builder->singleton('consumer', StaticConstructorAttributeConsumer::class);
    $path = staticAttributeArtifactPath();

    try {
        expect($builder->development()->get('consumer')->value)->toBe('fallback');

        $report = $builder->compile($path);
        $consumer = $builder->productionPrevalidated($path, $report['sha256'])->get('consumer');

        expect($report['compiled'])->toContain('consumer')
            ->and($consumer->value)->toBe('fallback');
    } finally {
        removeStaticAttributeArtifact($path);
    }
});

it('keeps registered custom property attribute resolvers as targeted runtime islands', function () {
    $builder = ContainerBuilder::create(uniqid('static_runtime_property_attribute_'));
    $builder->options()->registerAttributeResolver(
        StaticRuntimePropertyAttribute::class,
        StaticRuntimePropertyAttributeResolver::class,
    );
    $builder->options()->setOptions(propertyAttributes: true);
    $builder->singleton('consumer', StaticRuntimePropertyConsumer::class);
    $path = staticAttributeArtifactPath();

    try {
        $report = $builder->compile($path);
        $source = file_get_contents($path);
        $consumer = $builder->productionPrevalidated($path, $report['sha256'])->get('consumer');

        expect($report['compiled'])->toContain('consumer')
            ->and($source)->toContain('applyCompiledRuntimePropertyAttribute')
            ->and($consumer->value)->toBe('runtime-property');
    } finally {
        removeStaticAttributeArtifact($path);
    }
});
