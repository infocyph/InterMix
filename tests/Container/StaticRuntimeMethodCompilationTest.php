<?php

declare(strict_types=1);

use Infocyph\InterMix\DI\ContainerBuilder;
use Infocyph\InterMix\DI\Support\LifetimeEnum;

final class MethodCompiledDependency {}

final readonly class MethodRegisteredConstructor
{
    public function __construct(
        public MethodCompiledDependency $dependency,
        public string $label,
    ) {}
}

final class MethodRegisteredInvocation
{
    public int $calls = 0;
    public ?MethodCompiledDependency $dependency = null;
    public string $label = 'unset';

    public function boot(MethodCompiledDependency $dependency, string $label = 'default'): void
    {
        ++$this->calls;
        $this->dependency = $dependency;
        $this->label = $label;
    }
}

final class MethodRuntimeParameterInvocation
{
    /** @return array{MethodCompiledDependency, string} */
    public function run(MethodCompiledDependency $dependency, string $label = 'default'): array
    {
        return [$dependency, $label];
    }
}

final class MethodVariadicInvocation
{
    /** @return list<string> */
    public function run(string ...$labels): array
    {
        return $labels;
    }
}

final class MethodStaticInvocation
{
    public static ?MethodCompiledDependency $dependency = null;
    public static string $label = 'unset';

    public static function boot(MethodCompiledDependency $dependency, string $label = 'default'): void
    {
        self::$dependency = $dependency;
        self::$label = $label;
    }
}

final class MethodCallOnInvocation
{
    public const CALL_ON = 'boot';
    public ?MethodCompiledDependency $dependency = null;

    public function boot(MethodCompiledDependency $dependency): void
    {
        $this->dependency = $dependency;
    }
}

function methodCompilationArtifactPath(): string
{
    return sys_get_temp_dir() . '/intermix-method-' . bin2hex(random_bytes(8)) . '.php';
}

function removeMethodCompilationArtifact(string $path): void
{
    foreach ([$path, $path . '.meta.json'] as $artifact) {
        if (is_file($artifact)) {
            unlink($artifact);
        }
    }
}

it('compiles deterministic constructor arguments through the canonical graph', function () {
    $builder = ContainerBuilder::create(uniqid('method_constructor_'));
    $builder->autowire(MethodCompiledDependency::class, MethodCompiledDependency::class)
        ->autowire(
            MethodRegisteredConstructor::class,
            MethodRegisteredConstructor::class,
            ['label' => 'compiled-constructor'],
        );

    $development = $builder->build()->get(MethodRegisteredConstructor::class);
    $path = methodCompilationArtifactPath();

    try {
        $report = $builder->compile($path);
        $runtime = $builder->production($path);
        $service = $runtime->get(MethodRegisteredConstructor::class);

        expect($report['compiled'])->toContain(MethodRegisteredConstructor::class)
            ->and($development->label)->toBe('compiled-constructor')
            ->and($service->label)->toBe('compiled-constructor')
            ->and($service->dependency)->toBe($runtime->get(MethodCompiledDependency::class));
    } finally {
        removeMethodCompilationArtifact($path);
    }
});

it('invokes instance methods with injection and caller overrides in both runtimes', function () {
    $builder = ContainerBuilder::create(uniqid('method_runtime_parameters_'));
    $builder->autowire(MethodCompiledDependency::class, MethodCompiledDependency::class)
        ->autowire(
            MethodRuntimeParameterInvocation::class,
            MethodRuntimeParameterInvocation::class,
            lifetime: LifetimeEnum::Transient,
        );

    $development = $builder->build();
    $developmentResult = $development->invoke(
        [$development->make(MethodRuntimeParameterInvocation::class), 'run'],
        ['label' => 'runtime-named'],
    );
    $path = methodCompilationArtifactPath();

    try {
        $builder->compile($path);
        $runtime = $builder->production($path);
        $named = $runtime->invoke(
            [$runtime->make(MethodRuntimeParameterInvocation::class), 'run'],
            ['label' => 'runtime-named'],
        );
        $override = new MethodCompiledDependency();
        $positional = $runtime->invoke(
            [$runtime->make(MethodRuntimeParameterInvocation::class), 'run'],
            [0 => $override, 1 => 'runtime-positional'],
        );

        expect($developmentResult[1])->toBe('runtime-named')
            ->and($named[0])->toBe($runtime->get(MethodCompiledDependency::class))
            ->and($named[1])->toBe('runtime-named')
            ->and($positional)->toBe([$override, 'runtime-positional']);
    } finally {
        removeMethodCompilationArtifact($path);
    }
});

it('invokes static and variadic callables explicitly', function () {
    MethodStaticInvocation::$dependency = null;
    MethodStaticInvocation::$label = 'unset';

    $builder = ContainerBuilder::create(uniqid('method_explicit_'));
    $builder->autowire(MethodCompiledDependency::class, MethodCompiledDependency::class)
        ->autowire(MethodVariadicInvocation::class, MethodVariadicInvocation::class);
    $path = methodCompilationArtifactPath();

    try {
        $builder->compile($path);
        $runtime = $builder->production($path);

        $runtime->invoke([MethodStaticInvocation::class, 'boot'], ['label' => 'compiled-static']);
        $variadic = $runtime->invoke(
            [$runtime->get(MethodVariadicInvocation::class), 'run'],
            ['first', 'second'],
        );

        expect(MethodStaticInvocation::$dependency)
            ->toBe($runtime->get(MethodCompiledDependency::class))
            ->and(MethodStaticInvocation::$label)->toBe('compiled-static')
            ->and($variadic)->toBe(['first', 'second']);
    } finally {
        removeMethodCompilationArtifact($path);
        MethodStaticInvocation::$dependency = null;
        MethodStaticInvocation::$label = 'unset';
    }
});

it('does not infer post-construction methods', function () {
    $builder = ContainerBuilder::create(uniqid('method_explicit_only_'));
    $builder->autowire(MethodCompiledDependency::class, MethodCompiledDependency::class)
        ->autowire(MethodRegisteredInvocation::class, MethodRegisteredInvocation::class)
        ->autowire(MethodCallOnInvocation::class, MethodCallOnInvocation::class);
    $path = methodCompilationArtifactPath();

    try {
        $builder->compile($path);
        $runtime = $builder->production($path);
        $registered = $runtime->get(MethodRegisteredInvocation::class);
        $callOn = $runtime->get(MethodCallOnInvocation::class);

        expect(method_exists($builder, 'registerMethod'))->toBeFalse()
            ->and(method_exists($builder, 'setDefaultMethod'))->toBeFalse()
            ->and($registered->calls)->toBe(0)
            ->and($callOn->dependency)->toBeNull();

        $runtime->invoke([$registered, 'boot'], ['label' => 'explicit']);

        expect($registered->calls)->toBe(1)
            ->and($registered->label)->toBe('explicit')
            ->and($registered->dependency)->toBe($runtime->get(MethodCompiledDependency::class));
    } finally {
        removeMethodCompilationArtifact($path);
    }
});
