<?php

declare(strict_types=1);

use Infocyph\InterMix\DI\ContainerBuilder;

final class FreshInvocationDependency {}
final class FreshInvocationHandler
{
    public static int $instances = 0;
    public string $prefix = 'unset';
    public function __construct(public FreshInvocationDependency $constructorDependency) { ++self::$instances; }
    public function handle(FreshInvocationDependency $methodDependency, string $suffix = 'default'): string
    {
        return $this->prefix . ':' . $suffix . ':' .
            ($methodDependency === $this->constructorDependency ? 'same' : 'different');
    }
    public function nullable(): ?string { return null; }
}
final class FreshImplicitInvocation
{
    public const CALL_ON = 'boot';
    public static int $instances = 0;
    public function __construct() { ++self::$instances; }
    public function boot(FreshInvocationDependency $dependency): FreshInvocationDependency { return $dependency; }
}

function freshInvocationArtifactPath(): string
{
    return sys_get_temp_dir() . '/intermix-fresh-invocation-' . bin2hex(random_bytes(8)) . '.php';
}
function removeFreshInvocationArtifact(string $path): void
{
    foreach ([$path, $path . '.meta.json'] as $artifact) if (is_file($artifact)) unlink($artifact);
}

it('constructs fresh roots and invokes methods explicitly', function () {
    FreshInvocationHandler::$instances = 0;
    $builder = ContainerBuilder::create(uniqid('fresh_invocation_'))
        ->autowire(FreshInvocationDependency::class, FreshInvocationDependency::class)
        ->autowire(
            FreshInvocationHandler::class,
            FreshInvocationHandler::class,
            properties: ['prefix' => 'compiled'],
        );
    $path = freshInvocationArtifactPath();

    try {
        $builder->compile($path);
        $runtime = $builder->production($path);
        $first = $runtime->make(FreshInvocationHandler::class);
        $second = $runtime->make(FreshInvocationHandler::class);

        expect($runtime->invoke([$first, 'handle'], ['suffix' => 'explicit']))
            ->toBe('unset:explicit:same')
            ->and($runtime->invoke([$second, 'nullable']))->toBeNull()
            ->and($first)->not->toBe($second)
            ->and(FreshInvocationHandler::$instances)->toBe(2);
    } finally {
        removeFreshInvocationArtifact($path);
    }
});

it('does not invoke CALL_ON during construction', function () {
    FreshImplicitInvocation::$instances = 0;
    $builder = ContainerBuilder::create(uniqid('fresh_implicit_'))
        ->autowire(FreshInvocationDependency::class, FreshInvocationDependency::class)
        ->autowire(FreshImplicitInvocation::class, FreshImplicitInvocation::class);
    $path = freshInvocationArtifactPath();

    try {
        $builder->compile($path);
        $runtime = $builder->production($path);
        $instance = $runtime->make(FreshImplicitInvocation::class);
        expect($instance)->toBeInstanceOf(FreshImplicitInvocation::class)
            ->and($runtime->invoke([$instance, 'boot']))
            ->toBe($runtime->get(FreshInvocationDependency::class))
            ->and(FreshImplicitInvocation::$instances)->toBe(1);
    } finally {
        removeFreshInvocationArtifact($path);
    }
});
