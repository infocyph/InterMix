<?php

declare(strict_types=1);

use Infocyph\InterMix\DI\Attribute\Inject;
use Infocyph\InterMix\DI\ContainerBuilder;
use Infocyph\InterMix\DI\Support\FactoryDefinition;
use Infocyph\InterMix\DI\Support\LifetimeEnum;
use Infocyph\InterMix\DI\Support\ServiceReference;

final class AdvancedCompiledDependency {}

final class AdvancedRegisteredProperty
{
    public string $name = 'unset';
}

final class AdvancedInjectedProperty
{
    #[Inject]
    public AdvancedCompiledDependency $dependency;
}

final readonly class AdvancedConstructorAttribute
{
    public function __construct(
        #[Inject('advanced.dep')]
        public AdvancedCompiledDependency $dependency,
    ) {}
}

final readonly class AdvancedFactoryProduct
{
    public function __construct(
        public AdvancedCompiledDependency $dependency,
        public string $label,
    ) {}
}

final class AdvancedFactoryMaker
{
    public static function make(
        AdvancedCompiledDependency $dependency,
        string $label,
    ): AdvancedFactoryProduct {
        return new AdvancedFactoryProduct($dependency, $label);
    }
}

final class AdvancedProtectedProperty
{
    protected string $name = 'unset';

    public function name(): string
    {
        return $this->name;
    }
}

final readonly class AdvancedDeoptRoot
{
    public function __construct(public AdvancedCompiledDependency $dependency) {}
}

final class AdvancedDeoptScoped {}

function advancedCompilationArtifactPath(): string
{
    return sys_get_temp_dir() . '/intermix-advanced-' . bin2hex(random_bytes(8)) . '.php';
}

function removeAdvancedCompilationArtifact(string $path): void
{
    foreach ([$path, $path . '.meta.json'] as $artifact) {
        if (is_file($artifact)) {
            unlink($artifact);
        }
    }
}

it('compiles registered public property injection directly', function () {
    $builder = ContainerBuilder::create(uniqid('advanced_property_'));
    $builder->singleton(AdvancedRegisteredProperty::class);
    $builder->registration()->registerProperty(
        AdvancedRegisteredProperty::class,
        ['name' => 'compiled'],
    );

    expect($builder->development()->get(AdvancedRegisteredProperty::class)->name)->toBe('compiled');

    $path = advancedCompilationArtifactPath();
    try {
        $report = $builder->compile($path);
        $runtime = $builder->production($path);

        expect($report['compiled'])->toContain(AdvancedRegisteredProperty::class)
            ->and($runtime->get(AdvancedRegisteredProperty::class)->name)->toBe('compiled');
    } finally {
        removeAdvancedCompilationArtifact($path);
    }
});

it('compiles deterministic property Inject attributes', function () {
    $builder = ContainerBuilder::create(uniqid('advanced_inject_'));
    $builder->singleton(AdvancedCompiledDependency::class)
        ->singleton(AdvancedInjectedProperty::class);
    $builder->options()->setOptions(propertyAttributes: true);

    $path = advancedCompilationArtifactPath();
    try {
        $report = $builder->compile($path);
        $runtime = $builder->production($path);
        $property = $runtime->get(AdvancedInjectedProperty::class);

        expect($report['compiled'])->toContain(AdvancedInjectedProperty::class)
            ->and($property->dependency)->toBe($runtime->get(AdvancedCompiledDependency::class));
    } finally {
        removeAdvancedCompilationArtifact($path);
    }
});

it('compiles constructor parameter attributes with the current development semantics', function () {
    $builder = ContainerBuilder::create(uniqid('advanced_constructor_attr_'));
    $builder->singleton(AdvancedCompiledDependency::class)
        ->singleton('advanced.dep', AdvancedCompiledDependency::class)
        ->singleton(AdvancedConstructorAttribute::class);

    $path = advancedCompilationArtifactPath();
    try {
        $report = $builder->compile($path);
        $runtime = $builder->production($path);
        $constructor = $runtime->get(AdvancedConstructorAttribute::class);
        $typed = $runtime->get(AdvancedCompiledDependency::class);
        $explicit = $runtime->get('advanced.dep');

        expect($report['compiled'])->toContain(AdvancedConstructorAttribute::class)
            ->and($constructor->dependency)->toBe($typed)
            ->and($constructor->dependency)->not->toBe($explicit);
    } finally {
        removeAdvancedCompilationArtifact($path);
    }
});

it('compiles declarative constructor and static factories with service references', function () {
    $builder = ContainerBuilder::create(uniqid('advanced_factory_'));
    $builder->singleton(AdvancedCompiledDependency::class)
        ->bind(
            'factory.construct',
            FactoryDefinition::construct(
                AdvancedFactoryProduct::class,
                [new ServiceReference(AdvancedCompiledDependency::class), 'construct'],
            ),
            LifetimeEnum::Singleton,
        )
        ->bind(
            'factory.static',
            FactoryDefinition::staticFactory(
                AdvancedFactoryMaker::class,
                'make',
                [new ServiceReference(AdvancedCompiledDependency::class), 'static'],
            ),
            LifetimeEnum::Transient,
        );

    $path = advancedCompilationArtifactPath();
    try {
        $report = $builder->compile($path);
        $runtime = $builder->production($path);
        $construct = $runtime->get('factory.construct');
        $static = $runtime->get('factory.static');

        expect($report['compiled'])->toContain('factory.construct', 'factory.static')
            ->and($construct)->toBeInstanceOf(AdvancedFactoryProduct::class)
            ->and($construct->label)->toBe('construct')
            ->and($construct->dependency)->toBe($runtime->get(AdvancedCompiledDependency::class))
            ->and($runtime->get('factory.construct'))->toBe($construct)
            ->and($static)->toBeInstanceOf(AdvancedFactoryProduct::class)
            ->and($static->label)->toBe('static')
            ->and($runtime->get('factory.static'))->not->toBe($static);
    } finally {
        removeAdvancedCompilationArtifact($path);
    }
});

it('keeps reflection-only property writes as targeted compiled property islands', function () {
    $builder = ContainerBuilder::create(uniqid('advanced_protected_'));
    $builder->singleton(AdvancedProtectedProperty::class);
    $builder->registration()->registerProperty(
        AdvancedProtectedProperty::class,
        ['name' => 'compiled-reflection'],
    );

    $path = advancedCompilationArtifactPath();
    try {
        $report = $builder->compile($path);
        $source = file_get_contents($path);
        $runtime = $builder->production($path);

        expect($report['compiled'])->toContain(AdvancedProtectedProperty::class)
            ->and($source)->toContain('assignCompiledRuntimeProperty')
            ->and($runtime->get(AdvancedProtectedProperty::class)->name())->toBe('compiled-reflection');
    } finally {
        removeAdvancedCompilationArtifact($path);
    }
});

it('deoptimizes before builder mutation and preserves compiled singleton and scope identity', function () {
    $builder = ContainerBuilder::create(uniqid('advanced_deopt_'));
    $builder->singleton(AdvancedCompiledDependency::class)
        ->singleton(AdvancedDeoptRoot::class)
        ->scoped(AdvancedDeoptScoped::class);

    $path = advancedCompilationArtifactPath();
    try {
        $builder->compile($path);
        $runtime = $builder->production($path);
        $root = $runtime->get(AdvancedDeoptRoot::class);
        $runtime->enterScope('request');
        $scoped = $runtime->get(AdvancedDeoptScoped::class);

        $builder->value('late.value', 'available-after-deopt');

        expect($runtime->get(AdvancedDeoptRoot::class))->toBe($root)
            ->and($runtime->get(AdvancedCompiledDependency::class))->toBe($root->dependency)
            ->and($runtime->get(AdvancedDeoptScoped::class))->toBe($scoped)
            ->and($runtime->get('late.value'))->toBe('available-after-deopt');

        $runtime->leaveScope();
    } finally {
        removeAdvancedCompilationArtifact($path);
    }
});
