<?php

declare(strict_types=1);

use Infocyph\InterMix\DI\ContainerBuilder;

final class FreshInvocationDependency {}

final class FreshInvocationHandler
{
    public static int $instances = 0;

    public string $prefix = 'unset';

    public function __construct(public FreshInvocationDependency $constructorDependency)
    {
        ++self::$instances;
    }

    public function handle(FreshInvocationDependency $methodDependency, string $suffix = 'default'): string
    {
        return $this->prefix
            . ':' . $suffix
            . ':' . ($methodDependency === $this->constructorDependency ? 'same' : 'different');
    }

    public function nullable(): ?string
    {
        return null;
    }
}

final class FreshImplicitInvocation
{
    public const CALL_ON = 'boot';

    public static int $instances = 0;

    public function __construct()
    {
        ++self::$instances;
    }

    public function boot(FreshInvocationDependency $dependency): FreshInvocationDependency
    {
        return $dependency;
    }
}

function freshInvocationArtifactPath(): string
{
    return sys_get_temp_dir() . '/intermix-fresh-invocation-' . bin2hex(random_bytes(8)) . '.php';
}

function removeFreshInvocationArtifact(string $path): void
{
    foreach ([$path, $path . '.meta.json'] as $artifact) {
        if (is_file($artifact)) {
            unlink($artifact);
        }
    }
}

it('uses generated fresh recipes for explicit make and resolveNow method calls', function () {
    FreshInvocationHandler::$instances = 0;
    $builder = ContainerBuilder::create(uniqid('fresh_invocation_'));
    $builder->singleton(FreshInvocationDependency::class)
        ->singleton('handler.result', [FreshInvocationHandler::class, 'handle']);
    $builder->registration()
        ->registerProperty(FreshInvocationHandler::class, ['prefix' => 'compiled'])
        ->registerMethod(FreshInvocationHandler::class, 'handle', ['suffix' => 'registered']);

    $path = freshInvocationArtifactPath();
    try {
        $report = $builder->compile($path);
        $runtime = $builder->production($path);
        $dependency = $runtime->get(FreshInvocationDependency::class);
        $made = $runtime->make(FreshInvocationHandler::class, 'handle');
        $resolved = $runtime->resolveNow([FreshInvocationHandler::class, 'handle']);

        expect($report['compiled'])->toContain('handler.result')
            ->and($made)->toBe('compiled:registered:same')
            ->and($resolved)->toBe('compiled:registered:same')
            ->and($runtime->get(FreshInvocationDependency::class))->toBe($dependency)
            ->and(FreshInvocationHandler::$instances)->toBe(2);
    } finally {
        removeFreshInvocationArtifact($path);
    }
});

it('treats null as a handled compiled fresh invocation result', function () {
    FreshInvocationHandler::$instances = 0;
    $builder = ContainerBuilder::create(uniqid('fresh_invocation_null_'));
    $builder->singleton(FreshInvocationDependency::class)
        ->singleton('nullable.result', [FreshInvocationHandler::class, 'nullable']);

    $path = freshInvocationArtifactPath();
    try {
        $builder->compile($path);
        $runtime = $builder->production($path);

        expect($runtime->make(FreshInvocationHandler::class, 'nullable'))->toBeNull()
            ->and($runtime->resolveNow([FreshInvocationHandler::class, 'nullable']))->toBeNull()
            ->and(FreshInvocationHandler::$instances)->toBe(2);
    } finally {
        removeFreshInvocationArtifact($path);
    }
});

it('reuses a compiled class post-method recipe for explicit fresh invocation', function () {
    FreshImplicitInvocation::$instances = 0;
    $builder = ContainerBuilder::create(uniqid('fresh_implicit_invocation_'));
    $builder->singleton(FreshInvocationDependency::class)
        ->singleton(FreshImplicitInvocation::class);

    $path = freshInvocationArtifactPath();
    try {
        $report = $builder->compile($path);
        $runtime = $builder->production($path);
        $dependency = $runtime->get(FreshInvocationDependency::class);
        $made = $runtime->make(FreshImplicitInvocation::class, 'boot');
        $resolved = $runtime->resolveNow([FreshImplicitInvocation::class, 'boot']);

        expect($report['compiled'])->toContain(FreshImplicitInvocation::class)
            ->and($made)->toBe($dependency)
            ->and($resolved)->toBe($dependency)
            ->and(FreshImplicitInvocation::$instances)->toBe(2);
    } finally {
        removeFreshInvocationArtifact($path);
    }
});
