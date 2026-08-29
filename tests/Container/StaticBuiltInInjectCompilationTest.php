<?php

declare(strict_types=1);

use Infocyph\InterMix\DI\Attribute\Inject;
use Infocyph\InterMix\DI\ContainerBuilder;

final class StaticInjectLiteralDependency {}

final class StaticMethodLevelInjectConsumer
{
    public const string CALL_ON = 'boot';

    public string $literal = '';

    public string $message = '';

    #[Inject(message: 'config.message', literal: StaticInjectLiteralDependency::class)]
    public function boot(string $message, string $literal): void
    {
        $this->message = $message;
        $this->literal = $literal;
    }
}

final class StaticParameterLevelInjectConsumer
{
    public const string CALL_ON = 'boot';

    public string $message = '';

    public function boot(#[Inject('config.message')] string $message): void
    {
        $this->message = $message;
    }
}

final class StaticTypedMethodInjectConsumer
{
    public const string CALL_ON = 'boot';

    public ?StaticInjectLiteralDependency $dependency = null;

    #[Inject(dependency: 'dep')]
    public function boot(StaticInjectLiteralDependency $dependency): void
    {
        $this->dependency = $dependency;
    }
}

function staticBuiltInInjectArtifactPath(): string
{
    return sys_get_temp_dir() . '/intermix-static-inject-' . bin2hex(random_bytes(8)) . '.php';
}

function removeStaticBuiltInInjectArtifact(string $path): void
{
    foreach ([$path, $path . '.meta.json'] as $artifact) {
        if (is_file($artifact)) {
            unlink($artifact);
        }
    }
}

it('compiles deterministic method-level Inject arguments without changing literal string semantics', function () {
    $builder = ContainerBuilder::create(uniqid('static_method_inject_'));
    $builder->options()->setOptions(methodAttributes: true);
    $builder->value('config.message', 'compiled-message');
    $builder->singleton('consumer', StaticMethodLevelInjectConsumer::class);
    $path = staticBuiltInInjectArtifactPath();

    try {
        $report = $builder->compile($path);
        $consumer = $builder->productionPrevalidated($path, $report['sha256'])->get('consumer');

        expect($report['compiled'])->toContain('consumer', 'config.message')
            ->and($consumer)->toBeInstanceOf(StaticMethodLevelInjectConsumer::class)
            ->and($consumer->message)->toBe('compiled-message')
            ->and($consumer->literal)->toBe(StaticInjectLiteralDependency::class);
    } finally {
        removeStaticBuiltInInjectArtifact($path);
    }
});

it('compiles deterministic parameter-level Inject service targets', function () {
    $builder = ContainerBuilder::create(uniqid('static_parameter_inject_'));
    $builder->options()->setOptions(methodAttributes: true);
    $builder->value('config.message', 'parameter-message');
    $builder->singleton('consumer', StaticParameterLevelInjectConsumer::class);
    $path = staticBuiltInInjectArtifactPath();

    try {
        $report = $builder->compile($path);
        $consumer = $builder->productionPrevalidated($path, $report['sha256'])->get('consumer');

        expect($report['compiled'])->toContain('consumer', 'config.message')
            ->and($consumer)->toBeInstanceOf(StaticParameterLevelInjectConsumer::class)
            ->and($consumer->message)->toBe('parameter-message');
    } finally {
        removeStaticBuiltInInjectArtifact($path);
    }
});

it('keeps typed method-level Inject precedence as a targeted runtime method island', function () {
    $builder = ContainerBuilder::create(uniqid('static_typed_method_inject_'));
    $builder->options()->setOptions(methodAttributes: true);
    $builder->singleton('dep', StaticInjectLiteralDependency::class);
    $builder->singleton('consumer', StaticTypedMethodInjectConsumer::class);
    $path = staticBuiltInInjectArtifactPath();

    try {
        $report = $builder->compile($path);
        $source = file_get_contents($path);
        $consumer = $builder->productionPrevalidated($path, $report['sha256'])->get('consumer');

        expect($report['compiled'])->toContain('consumer')
            ->and($source)->toContain('invokeCompiledRuntimeMethod')
            ->and($consumer->dependency)->toBeInstanceOf(StaticInjectLiteralDependency::class);
    } finally {
        removeStaticBuiltInInjectArtifact($path);
    }
});
