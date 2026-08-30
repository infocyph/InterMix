<?php

declare(strict_types=1);

use Infocyph\InterMix\DI\ContainerBuilder;

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

final class MethodInvokableInvocation
{
    public ?MethodCompiledDependency $dependency = null;

    public function __invoke(MethodCompiledDependency $dependency): void
    {
        $this->dependency = $dependency;
    }
}

final class MethodDefaultInvocation
{
    public ?MethodCompiledDependency $dependency = null;

    public function boot(MethodCompiledDependency $dependency): void
    {
        $this->dependency = $dependency;
    }
}

final class MethodProtectedInvocation
{
    public const CALL_ON = 'boot';

    public bool $called = false;

    protected function boot(): void
    {
        $this->called = true;
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

it('compiles deterministic registered constructor parameters', function () {
    $builder = ContainerBuilder::create(uniqid('method_constructor_'));
    $builder->singleton(MethodCompiledDependency::class)
        ->singleton(MethodRegisteredConstructor::class);
    $builder->registration()->registerClass(
        MethodRegisteredConstructor::class,
        ['label' => 'compiled-constructor'],
    );

    expect($builder->development()->get(MethodRegisteredConstructor::class)->label)
        ->toBe('compiled-constructor');

    $path = methodCompilationArtifactPath();
    try {
        $report = $builder->compile($path);
        $runtime = $builder->production($path);
        $service = $runtime->get(MethodRegisteredConstructor::class);

        expect($report['compiled'])->toContain(MethodRegisteredConstructor::class)
            ->and($service->label)->toBe('compiled-constructor')
            ->and($service->dependency)->toBe($runtime->get(MethodCompiledDependency::class));
    } finally {
        removeMethodCompilationArtifact($path);
    }
});

it('compiles registered post-construction method invocation', function () {
    $builder = ContainerBuilder::create(uniqid('method_registered_'));
    $builder->singleton(MethodCompiledDependency::class)
        ->singleton(MethodRegisteredInvocation::class);
    $builder->registration()->registerMethod(
        MethodRegisteredInvocation::class,
        'boot',
        ['label' => 'compiled-method'],
    );

    $path = methodCompilationArtifactPath();
    try {
        $report = $builder->compile($path);
        $runtime = $builder->production($path);
        $service = $runtime->get(MethodRegisteredInvocation::class);

        expect($report['compiled'])->toContain(MethodRegisteredInvocation::class)
            ->and($service->calls)->toBe(1)
            ->and($service->label)->toBe('compiled-method')
            ->and($service->dependency)->toBe($runtime->get(MethodCompiledDependency::class))
            ->and($runtime->get(MethodRegisteredInvocation::class))->toBe($service)
            ->and($service->calls)->toBe(1);
    } finally {
        removeMethodCompilationArtifact($path);
    }
});

it('compiles public static methods without a reflection island', function () {
    MethodStaticInvocation::$dependency = null;
    MethodStaticInvocation::$label = 'unset';

    $builder = ContainerBuilder::create(uniqid('method_static_'));
    $builder->singleton(MethodCompiledDependency::class)
        ->singleton(MethodStaticInvocation::class);
    $builder->registration()->registerMethod(
        MethodStaticInvocation::class,
        'boot',
        ['label' => 'compiled-static'],
    );

    $path = methodCompilationArtifactPath();
    try {
        $report = $builder->compile($path);
        $source = file_get_contents($path);
        $runtime = $builder->production($path);
        $service = $runtime->get(MethodStaticInvocation::class);

        expect($report['compiled'])->toContain(MethodStaticInvocation::class)
            ->and($source)->toBeString()
            ->toContain('\\MethodStaticInvocation::boot(')
            ->and($service)->toBeInstanceOf(MethodStaticInvocation::class)
            ->and(MethodStaticInvocation::$label)->toBe('compiled-static')
            ->and(MethodStaticInvocation::$dependency)->toBe($runtime->get(MethodCompiledDependency::class));
    } finally {
        removeMethodCompilationArtifact($path);
        MethodStaticInvocation::$dependency = null;
        MethodStaticInvocation::$label = 'unset';
    }
});

it('compiles supplied named and positional method arguments over the static plan', function () {
    $builder = ContainerBuilder::create(uniqid('method_runtime_parameters_'));
    $builder->singleton(MethodCompiledDependency::class)
        ->transient(MethodRuntimeParameterInvocation::class);
    $builder->registration()->registerMethod(MethodRuntimeParameterInvocation::class, 'run');

    $development = $builder->development();
    $developmentNamed = $development->resolveNow(
        [MethodRuntimeParameterInvocation::class, 'run'],
        ['label' => 'runtime-named'],
    );

    $path = methodCompilationArtifactPath();
    try {
        $report = $builder->compile($path);
        $source = file_get_contents($path);
        $runtime = $builder->production($path);
        $named = $runtime->resolveNow(
            [MethodRuntimeParameterInvocation::class, 'run'],
            ['label' => 'runtime-named'],
        );
        $override = new MethodCompiledDependency();
        $positional = $runtime->resolveNow(
            [MethodRuntimeParameterInvocation::class, 'run'],
            [0 => $override, 1 => 'runtime-positional'],
        );

        expect($report['compiled'])->toContain(MethodRuntimeParameterInvocation::class)
            ->and($source)->toBeString()
            ->toContain('freshCompiledInvocationWithParameters(')
            ->toContain("array_key_exists('label', \$parameters)")
            ->and($developmentNamed[1])->toBe('runtime-named')
            ->and($named[0])->toBe($runtime->get(MethodCompiledDependency::class))
            ->and($named[1])->toBe($developmentNamed[1])
            ->and($positional[0])->toBe($override)
            ->and($positional[1])->toBe('runtime-positional');
    } finally {
        removeMethodCompilationArtifact($path);
    }
});

it('keeps variadic runtime arguments on the existing dynamic resolver', function () {
    $builder = ContainerBuilder::create(uniqid('method_variadic_parameters_'));
    $builder->transient(MethodVariadicInvocation::class);
    $builder->registration()->registerMethod(MethodVariadicInvocation::class, 'run');
    $path = methodCompilationArtifactPath();

    try {
        $report = $builder->compile($path);
        $source = file_get_contents($path);
        $runtime = $builder->production($path);
        $result = $runtime->resolveNow(
            [MethodVariadicInvocation::class, 'run'],
            ['first', 'second'],
        );

        expect($report['compiled'])->toContain(MethodVariadicInvocation::class)
            ->and($source)->toBeString()
            ->not->toContain('freshCompiledInvocationWithParameters(')
            ->and($result)->toBe(['first', 'second']);
    } finally {
        removeMethodCompilationArtifact($path);
    }
});

it('compiles CALL_ON and invokable post-construction methods while fresh make skips them', function () {
    $builder = ContainerBuilder::create(uniqid('method_implicit_'));
    $builder->singleton(MethodCompiledDependency::class)
        ->singleton(MethodCallOnInvocation::class)
        ->singleton(MethodInvokableInvocation::class);

    $path = methodCompilationArtifactPath();
    try {
        $report = $builder->compile($path);
        $runtime = $builder->production($path);
        $callOn = $runtime->get(MethodCallOnInvocation::class);
        $invokable = $runtime->get(MethodInvokableInvocation::class);
        $fresh = $runtime->make(MethodInvokableInvocation::class);
        $resolvedNow = $runtime->resolveNow(MethodInvokableInvocation::class);

        expect($report['compiled'])->toContain(
            MethodCallOnInvocation::class,
            MethodInvokableInvocation::class,
        )
            ->and($callOn->dependency)->toBe($runtime->get(MethodCompiledDependency::class))
            ->and($invokable->dependency)->toBe($runtime->get(MethodCompiledDependency::class))
            ->and($fresh)->toBeInstanceOf(MethodInvokableInvocation::class)
            ->and($fresh->dependency)->toBeNull()
            ->and($resolvedNow)->toBeInstanceOf(MethodInvokableInvocation::class)
            ->and($resolvedNow->dependency)->toBeNull();
    } finally {
        removeMethodCompilationArtifact($path);
    }
});

it('compiles the configured default method when it is statically resolvable', function () {
    $builder = ContainerBuilder::create(uniqid('method_default_'));
    $builder->singleton(MethodCompiledDependency::class)
        ->singleton(MethodDefaultInvocation::class);
    $builder->options()->setOptions(defaultMethod: 'boot');

    $path = methodCompilationArtifactPath();
    try {
        $report = $builder->compile($path);
        $runtime = $builder->production($path);
        $service = $runtime->get(MethodDefaultInvocation::class);

        expect($report['compiled'])->toContain(MethodDefaultInvocation::class)
            ->and($service->dependency)->toBe($runtime->get(MethodCompiledDependency::class));
    } finally {
        removeMethodCompilationArtifact($path);
    }
});

it('keeps non-public implicit methods as targeted reflection islands', function () {
    $builder = ContainerBuilder::create(uniqid('method_protected_'));
    $builder->singleton(MethodProtectedInvocation::class);

    $path = methodCompilationArtifactPath();
    try {
        $report = $builder->compile($path);
        $source = file_get_contents($path);
        $runtime = $builder->production($path);
        $service = $runtime->get(MethodProtectedInvocation::class);

        expect($report['compiled'])->toContain(MethodProtectedInvocation::class)
            ->and($source)->toContain('invokeCompiledRuntimeMethod')
            ->and($service->called)->toBeTrue();
    } finally {
        removeMethodCompilationArtifact($path);
    }
});
