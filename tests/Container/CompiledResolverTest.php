<?php

declare(strict_types=1);

use Infocyph\InterMix\DI\Attribute\Infuse;
use Infocyph\InterMix\DI\Container;
use Infocyph\InterMix\DI\Invoker\CompiledCall;
use Infocyph\InterMix\DI\Invoker\InjectedCall;
use Infocyph\InterMix\DI\Support\FactoryDefinition;
use Infocyph\InterMix\DI\Support\LifetimeEnum;
use Infocyph\InterMix\DI\Support\ServiceReference;
use Infocyph\InterMix\DI\Support\TraceLevelEnum;
use Infocyph\InterMix\Exceptions\ContainerException;
use Infocyph\InterMix\Internal\AtomicFileWriter;
use Infocyph\InterMix\Tests\Fixture\ExampleAttr;
use Infocyph\InterMix\Tests\Fixture\ExampleAttrResolver;

final class CompiledResolverDependency {}

final class CompiledResolverProduct
{
    public function __construct(
        public mixed $dependency = null,
        public mixed $value = null,
    ) {}

    public static function create(mixed $dependency, mixed $value): self
    {
        return new self($dependency, $value);
    }

    public function nonStatic(): self
    {
        return $this;
    }
}

final class CompiledResolverAlternative {}

final class CompiledResolverVariadic
{
    public function __construct(CompiledResolverDependency ...$dependencies) {}
}

final class CompiledResolverContextConsumer
{
    public function __construct(public CompiledResolverDependency $service) {}
}

final class CompiledResolverNamedConsumer
{
    public function __construct(public CompiledResolverDependency $namedDependency) {}
}

final class CompiledResolverAttributedConstructor
{
    public CompiledResolverDependency $annotated;

    public function __construct(#[Infuse] CompiledResolverDependency $annotated)
    {
        $this->annotated = $annotated;
    }
}

final class CompiledResolverAttributedProperty
{
    #[Infuse(CompiledResolverDependency::class)]
    public mixed $injected = null;
}

final class CompiledResolverCustomAttributedProperty
{
    #[ExampleAttr('compiled')]
    public string $injected = '';
}

final class CompiledResolverRegisteredProperty
{
    public string $configured = '';
}

final class CompiledResolverRegisteredMethod
{
    public bool $called = false;

    public function boot(): void
    {
        $this->called = true;
    }
}

final class CompiledResolverUnionDependency
{
    public function __construct(
        public CompiledResolverDependency|CompiledResolverAlternative $service,
    ) {}
}

final class CompiledResolverDuplicateDependency
{
    public function __construct(
        public CompiledResolverDependency $first,
        public CompiledResolverDependency $second,
    ) {}
}

final class CompiledResolverInvokable
{
    public bool $called = false;

    public function __invoke(): void
    {
        $this->called = true;
    }
}

final class CompiledResolverCallOn
{
    public const string CALL_ON = 'boot';

    public bool $called = false;

    public function boot(): void
    {
        $this->called = true;
    }
}

final class CompiledResolverDefaultMethod
{
    public bool $called = false;

    public function boot(): void
    {
        $this->called = true;
    }
}

function compiledResolverPath(): string
{
    return sys_get_temp_dir() . '/intermix-compiled-' . uniqid('', true) . '.php';
}

it('resolves declarative constructors and static factories dynamically and when compiled', function () {
    $literal = "quoted value '); this remains data\n";
    $container = Container::instance(uniqid('declarative_'));
    $container->bind('dependency', CompiledResolverDependency::class, LifetimeEnum::Transient);
    $container->bind(
        'constructed',
        FactoryDefinition::construct(CompiledResolverProduct::class, [
            new ServiceReference('dependency'),
            $literal,
        ]),
        LifetimeEnum::Transient,
    );
    $container->bind(
        'static',
        FactoryDefinition::staticFactory(CompiledResolverProduct::class, 'create', [
            new ServiceReference('dependency'),
            ['nested' => ['safe' => true]],
        ]),
        LifetimeEnum::Transient,
    );

    $dynamic = $container->get('constructed');
    $path = compiledResolverPath();
    $container->compileTo($path, load: true);
    $compiled = $container->get('constructed');
    $static = $container->get('static');

    expect($dynamic)->toBeInstanceOf(CompiledResolverProduct::class)
        ->and($dynamic->dependency)->toBeInstanceOf(CompiledResolverDependency::class)
        ->and($dynamic->value)->toBe($literal)
        ->and($compiled)->toBeInstanceOf(CompiledResolverProduct::class)
        ->and($compiled->dependency)->toBeInstanceOf(CompiledResolverDependency::class)
        ->and($compiled->value)->toBe($literal)
        ->and($static->value)->toBe(['nested' => ['safe' => true]]);
});

it('rejects non-declarative factory inputs at their registration boundary', function () {
    $resource = fopen('php://memory', 'rb');

    expect(fn() => FactoryDefinition::construct(CompiledResolverProduct::class, ['named' => 'value']))
        ->toThrow(InvalidArgumentException::class)
        ->and(fn() => FactoryDefinition::construct(CompiledResolverProduct::class, [static fn() => null]))
        ->toThrow(InvalidArgumentException::class)
        ->and(fn() => FactoryDefinition::construct(CompiledResolverProduct::class, [$resource]))
        ->toThrow(InvalidArgumentException::class)
        ->and(fn() => new ServiceReference(''))
        ->toThrow(InvalidArgumentException::class);

    if (is_resource($resource)) {
        fclose($resource);
    }
});

it('reports invalid declarative targets during compilation without publishing them', function () {
    $container = Container::instance(uniqid('invalid_declarative_'));
    $container->bind(
        'non-static',
        FactoryDefinition::staticFactory(CompiledResolverProduct::class, 'nonStatic'),
    );
    $container->bind(
        'missing-class',
        FactoryDefinition::construct('Missing\\DeclarativeFactoryTarget'),
    );
    $container->compileTo(compiledResolverPath(), load: true);

    $report = $container->compilationReport();
    expect($report['skipped'])->toHaveKeys(['non-static', 'missing-class'])
        ->and($container->getRepository()->getCompiledResolver('non-static'))->toBeNull()
        ->and($container->getRepository()->getCompiledResolver('missing-class'))->toBeNull();
});

it('reports compiled and deliberately dynamic definitions', function () {
    $container = Container::instance(uniqid('compile_report_'));
    $container->bind('compiled', CompiledResolverDependency::class);
    $container->bind('closure', static fn() => new stdClass());
    $container->bindFactory('direct', static fn() => new stdClass());
    $container->value('literal', 'value');
    $container->bind('variadic', CompiledResolverVariadic::class);
    $container->compileTo(compiledResolverPath());

    $report = $container->compilationReport();

    expect($report)->not->toBeNull()
        ->and($report['compiled'])->toContain('compiled')
        ->and($report['skipped'])->toHaveKeys(['closure', 'direct', 'literal', 'variadic'])
        ->and($container->getRepository()->getCompiledResolver('compiled'))->toBeNull();
});

it('fuses identical compiled expressions into one dispatcher arm', function () {
    $path = compiledResolverPath();
    $container = Container::instance(uniqid('compile_fusion_'));
    $container->bind('fused.first', CompiledResolverDependency::class, LifetimeEnum::Transient);
    $container->bind('fused.second', CompiledResolverDependency::class, LifetimeEnum::Transient);
    $container->compileTo($path);

    $artifact = file_get_contents($path);

    expect($artifact)->toBeString()
        ->and($artifact)->toContain("'fused.first', 'fused.second' =>");
});

it('falls back before automatic compilation can bypass dynamic injection semantics', function () {
    $container = Container::instance(uniqid('semantic_fallback_'));
    $contextual = new CompiledResolverDependency();
    $named = new CompiledResolverDependency();

    $container->options()->setOptions(propertyAttributes: true);
    $container->options()->registerAttributeResolver(ExampleAttr::class, ExampleAttrResolver::class);
    $container->value('namedDependency', $named);
    $container->when(CompiledResolverContextConsumer::class)
        ->needs(CompiledResolverDependency::class)
        ->give($contextual);
    $container->registration()->registerClass(CompiledResolverProduct::class, [
        'dependency' => new CompiledResolverDependency(),
        'value' => 'registered',
    ]);
    $container->registration()->registerProperty(CompiledResolverRegisteredProperty::class, [
        'configured' => 'registered',
    ]);
    $container->registration()->registerMethod(CompiledResolverRegisteredMethod::class, 'boot');
    $container->bind('contextual', CompiledResolverContextConsumer::class, LifetimeEnum::Transient);
    $container->bind('named-consumer', CompiledResolverNamedConsumer::class, LifetimeEnum::Transient);
    $container->bind('attributed.constructor', CompiledResolverAttributedConstructor::class, LifetimeEnum::Transient);
    $container->bind('attributed.property', CompiledResolverAttributedProperty::class, LifetimeEnum::Transient);
    $container->bind(
        'attributed.custom-property',
        CompiledResolverCustomAttributedProperty::class,
        LifetimeEnum::Transient,
    );
    $container->bind('registered.resource', CompiledResolverProduct::class, LifetimeEnum::Transient);
    $container->bind('registered.property', CompiledResolverRegisteredProperty::class, LifetimeEnum::Transient);
    $container->bind('registered.method', CompiledResolverRegisteredMethod::class, LifetimeEnum::Transient);
    $container->bind('duplicate', CompiledResolverDuplicateDependency::class, LifetimeEnum::Transient);
    $container->bind('union', CompiledResolverUnionDependency::class, LifetimeEnum::Transient);
    $container->bind('invokable', CompiledResolverInvokable::class, LifetimeEnum::Transient);
    $container->bind('call-on', CompiledResolverCallOn::class, LifetimeEnum::Transient);
    $container->compileTo(compiledResolverPath(), load: true);

    $defaultContainer = Container::instance(uniqid('default_method_fallback_'));
    $defaultContainer->options()->setOptions(defaultMethod: 'boot');
    $defaultContainer->bind('default-method', CompiledResolverDefaultMethod::class, LifetimeEnum::Transient);
    $defaultContainer->compileTo(compiledResolverPath(), load: true);

    $report = $container->compilationReport();
    $contextualConsumer = $container->get('contextual');
    $namedConsumer = $container->get('named-consumer');
    $propertyConsumer = $container->get('attributed.property');
    $customPropertyConsumer = $container->get('attributed.custom-property');
    $resourceConsumer = $container->get('registered.resource');
    $registeredProperty = $container->get('registered.property');
    $registeredMethod = $container->get('registered.method');
    $invokable = $container->get('invokable');
    $callOn = $container->get('call-on');
    $defaultMethod = $defaultContainer->get('default-method');
    $defaultReport = $defaultContainer->compilationReport();

    expect($report['compiled'])->toBe([])
        ->and($report['skipped']['contextual'])->toContain('contextual binding')
        ->and($report['skipped']['named-consumer'])->toContain('named definition')
        ->and($report['skipped']['attributed.constructor'])->toContain('has attributes')
        ->and($report['skipped']['attributed.property'])->toContain('property attribute')
        ->and($report['skipped']['attributed.custom-property'])->toContain('property attribute')
        ->and($report['skipped']['registered.resource'])->toContain('registered constructor')
        ->and($report['skipped']['registered.property'])->toContain('registered property')
        ->and($report['skipped']['registered.method'])->toContain('registered method')
        ->and($report['skipped']['duplicate'])->toContain('occurs more than once')
        ->and($report['skipped']['union'])->toContain('union or intersection')
        ->and($report['skipped']['invokable'])->toContain('implicit method')
        ->and($report['skipped']['call-on'])->toContain('implicit method')
        ->and($defaultReport['skipped']['default-method'])->toContain("'boot'")
        ->and($contextualConsumer->service)->toBe($contextual)
        ->and($namedConsumer->namedDependency)->toBe($named)
        ->and($propertyConsumer->injected)->toBeInstanceOf(CompiledResolverDependency::class)
        ->and($customPropertyConsumer->injected)->toBe('COMPILED')
        ->and($resourceConsumer->value)->toBe('registered')
        ->and($registeredProperty->configured)->toBe('registered')
        ->and($registeredMethod->called)->toBeTrue()
        ->and($invokable->called)->toBeTrue()
        ->and($callOn->called)->toBeTrue()
        ->and($defaultMethod->called)->toBeTrue();
});

it('keeps closures and direct factories dynamic after a compiled map is active', function () {
    $container = Container::instance(uniqid('dynamic_factories_'));
    $calls = 0;
    $container->bind('closure', function () use (&$calls): int {
        return ++$calls;
    }, LifetimeEnum::Transient);
    $container->bindFactory('direct', function () use (&$calls): int {
        return ++$calls;
    }, LifetimeEnum::Transient);
    $container->compileTo(compiledResolverPath(), load: true);

    expect($container->getRepository()->getCompiledResolver('closure'))->toBeNull()
        ->and($container->getRepository()->getCompiledResolver('direct'))->toBeNull()
        ->and($container->get('closure'))->toBe(1)
        ->and($container->get('direct'))->toBe(2);
});

it('keeps reflection resolvers lazy for compiled-only resolution', function () {
    $dynamicContainer = Container::instance(uniqid('dynamic_resolver_mode_'));

    expect($dynamicContainer->getCurrentResolver())->toBeInstanceOf(InjectedCall::class);

    $container = Container::instance(uniqid('compiled_lazy_reflection_'));
    $container->bind('compiled', CompiledResolverDependency::class, LifetimeEnum::Transient);
    $container->bind('dynamic', CompiledResolverVariadic::class, LifetimeEnum::Transient);
    $container->compileTo(compiledResolverPath(), load: true);

    $resolver = $container->getCurrentResolver();
    $dynamicResolver = new ReflectionProperty($resolver, 'dynamicResolver');

    expect($resolver)->toBeInstanceOf(CompiledCall::class)
        ->and($dynamicResolver->getValue($resolver))->toBeNull()
        ->and($container->get('compiled'))->toBeInstanceOf(CompiledResolverDependency::class)
        ->and($dynamicResolver->getValue($resolver))->toBeNull();

    $container->get('dynamic');

    expect($dynamicResolver->getValue($resolver))->toBeInstanceOf(InjectedCall::class);
});

it('preserves singleton transient and scoped lifetimes around compiled recipes', function () {
    $container = Container::instance(uniqid('compiled_lifetimes_'));
    $recipe = FactoryDefinition::construct(CompiledResolverProduct::class);
    $container->bind('singleton', $recipe, LifetimeEnum::Singleton);
    $container->bind('transient', $recipe, LifetimeEnum::Transient);
    $container->bind('scoped', $recipe, LifetimeEnum::Scoped);
    $container->compileTo(compiledResolverPath(), load: true);

    $singleton = $container->get('singleton');
    $transient = $container->get('transient');
    $firstScope = $container->enterScope('first')->get('scoped');

    expect($container->get('singleton'))->toBe($singleton)
        ->and($container->get('transient'))->not->toBe($transient)
        ->and($container->get('scoped'))->toBe($firstScope);

    $container->leaveScope();
    $secondScope = $container->enterScope('second')->get('scoped');
    expect($secondScope)->not->toBe($firstScope);
    $container->leaveScope();
});

it('preserves tag lookup around compiled recipes', function () {
    $container = Container::instance(uniqid('compiled_tags_'));
    $container->bind(
        'tagged.service',
        FactoryDefinition::construct(CompiledResolverProduct::class),
        tags: ['compiled'],
    );
    $container->compileTo(compiledResolverPath(), load: true);

    $tagged = $container->findByTag('compiled');

    expect($tagged)->toHaveKey('tagged.service')
        ->and($tagged['tagged.service'])->toBeInstanceOf(CompiledResolverProduct::class);
});

it('preserves hooks tracing and cycle detection around compiled recipes', function () {
    $container = Container::instance(uniqid('compiled_lifecycle_'));
    $events = [];
    $container->options()->enableDebugTracing(true, TraceLevelEnum::Verbose)->end();
    $container->bind('dependency', CompiledResolverDependency::class, LifetimeEnum::Transient);
    $container->bind(
        'root',
        FactoryDefinition::construct(CompiledResolverProduct::class, [new ServiceReference('dependency')]),
        LifetimeEnum::Transient,
    );
    $container->onResolving('root', static function (string $id) use (&$events): void {
        $events[] = "resolving:$id";
    });
    $container->onResolved('root', static function (string $id) use (&$events): void {
        $events[] = "resolved:$id";
    });
    $container->compileTo(compiledResolverPath(), load: true);

    $container->get('root');
    $graph = $container->exportGraph();
    $hasEdge = false;
    foreach ($graph['edges'] as $edge) {
        if ($edge['from'] === 'root' && $edge['to'] === 'dependency') {
            $hasEdge = true;

            break;
        }
    }

    expect($events)->toBe(['resolving:root', 'resolved:root'])
        ->and($hasEdge)->toBeTrue();

    $cycle = Container::instance(uniqid('compiled_cycle_'));
    $cycle->bind(
        'cycle',
        FactoryDefinition::construct(CompiledResolverProduct::class, [new ServiceReference('cycle')]),
        LifetimeEnum::Transient,
    );
    $cycle->compileTo(compiledResolverPath(), load: true);
    expect(fn() => $cycle->get('cycle'))->toThrow(ContainerException::class, 'Circular dependency');
});

it('rejects stale artifacts before replacing an active resolver map', function () {
    $goodPath = compiledResolverPath();
    $good = Container::instance(uniqid('artifact_good_'));
    $good->bind('service', CompiledResolverDependency::class, LifetimeEnum::Transient);
    $good->compileTo($goodPath);

    $target = Container::instance(uniqid('artifact_target_'));
    $target->bind('service', CompiledResolverDependency::class, LifetimeEnum::Transient);
    $target->useCompiled($goodPath);
    $active = $target->getRepository()->getCompiledResolver('service');

    $stalePath = compiledResolverPath();
    $stale = Container::instance(uniqid('artifact_stale_'));
    $stale->bind('service', CompiledResolverAlternative::class, LifetimeEnum::Transient);
    $stale->compileTo($stalePath);

    expect(fn() => $target->useCompiled($stalePath))
        ->toThrow(ContainerException::class, 'stale or incompatible')
        ->and($target->getRepository()->getCompiledResolver('service'))->toBe($active)
        ->and($target->get('service'))->toBeInstanceOf(CompiledResolverDependency::class);
});

it('requires registration before loading and rejects environment mismatches', function () {
    $path = compiledResolverPath();
    $source = Container::instance(uniqid('artifact_env_source_'));
    $source->bind('service', CompiledResolverDependency::class);
    $source->setEnvironment('production');
    $source->compileTo($path);

    $empty = Container::instance(uniqid('artifact_empty_'));
    expect(fn() => $empty->useCompiled($path))->toThrow(ContainerException::class);

    $otherEnvironment = Container::instance(uniqid('artifact_env_target_'));
    $otherEnvironment->bind('service', CompiledResolverDependency::class);
    $otherEnvironment->setEnvironment('testing');
    expect(fn() => $otherEnvironment->useCompiled($path))
        ->toThrow(ContainerException::class, 'stale or incompatible');

    $extraDefinition = Container::instance(uniqid('artifact_extra_target_'));
    $extraDefinition->bind('service', CompiledResolverDependency::class);
    $extraDefinition->bind('extra', CompiledResolverAlternative::class);
    $extraDefinition->setEnvironment('production');
    expect(fn() => $extraDefinition->useCompiled($path))
        ->toThrow(ContainerException::class, 'stale or incompatible');

    $dynamicExtra = Container::instance(uniqid('artifact_dynamic_target_'));
    $dynamicExtra->bind('service', CompiledResolverDependency::class);
    $dynamicExtra->bind('dynamic.extra', static fn() => new stdClass());
    $dynamicExtra->setEnvironment('production');
    expect(fn() => $dynamicExtra->useCompiled($path))
        ->toThrow(ContainerException::class, 'stale or incompatible');
});

it('loads a deployment-prevalidated artifact and rejects a mismatched manifest fingerprint', function () {
    $source = Container::instance(uniqid('prevalidated_source_'));
    $source->bind('service', CompiledResolverDependency::class, LifetimeEnum::Transient);
    $path = compiledResolverPath();
    $source->compileTo($path);
    $fingerprint = (string) $source->compilationReport()['fingerprint'];

    $runtime = Container::instance(uniqid('prevalidated_runtime_'));
    $runtime->bind('service', CompiledResolverDependency::class, LifetimeEnum::Transient);
    $runtime->usePrevalidated($path, $fingerprint);

    $rejected = Container::instance(uniqid('prevalidated_rejected_'));
    $rejected->bind('service', CompiledResolverDependency::class, LifetimeEnum::Transient);

    expect($runtime->get('service'))->toBeInstanceOf(CompiledResolverDependency::class)
        ->and(fn() => $rejected->usePrevalidated($path, str_repeat('0', 64)))
        ->toThrow(ContainerException::class)
        ->and($rejected->getRepository()->getCompiledResolver('service'))->toBeNull()
        ->and(fn() => $rejected->usePrevalidated($path, 'invalid'))
        ->toThrow(ContainerException::class);
});

it('rejects malformed service IDs in a prevalidated artifact', function () {
    $path = compiledResolverPath();
    $fingerprint = str_repeat('a', 64);
    $php = PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION;
    file_put_contents($path, <<<PHP
        <?php

        return [
            'metadata' => [
                'format' => 3,
                'php' => '{$php}',
                'fingerprint' => '{$fingerprint}',
                'compiled' => [0 => 'invalid'],
            ],
            'resolver' => static fn(mixed \$container, string \$id): mixed => null,
        ];
        PHP);

    $container = Container::instance(uniqid('prevalidated_malformed_'));

    expect(fn() => $container->usePrevalidated($path, $fingerprint))
        ->toThrow(ContainerException::class, 'metadata is malformed');
});

it('rejects artifacts when resolution-affecting registration changes', function () {
    $path = compiledResolverPath();
    $source = Container::instance(uniqid('artifact_resolution_source_'));
    $source->bind('consumer', CompiledResolverContextConsumer::class);
    $source->compileTo($path);

    $target = Container::instance(uniqid('artifact_resolution_target_'));
    $target->bind('consumer', CompiledResolverContextConsumer::class);
    $target->when(CompiledResolverContextConsumer::class)
        ->needs(CompiledResolverDependency::class)
        ->give(new CompiledResolverDependency());

    expect(fn() => $target->useCompiled($path))
        ->toThrow(ContainerException::class, 'stale or incompatible')
        ->and($target->getRepository()->getCompiledResolver('consumer'))->toBeNull();
});

it('invalidates active compiled resolvers on definition context environment and option mutations', function () {
    $newContainer = static function (string $suffix): Container {
        $container = Container::instance(uniqid("invalidate_{$suffix}_"));
        $container->bind('service', CompiledResolverDependency::class, LifetimeEnum::Transient);
        $container->compileTo(compiledResolverPath(), load: true);

        return $container;
    };

    $definition = $newContainer('definition');
    $definition->bind('other', CompiledResolverAlternative::class);
    expect($definition->getRepository()->getCompiledResolver('service'))->toBeNull();

    $context = $newContainer('context');
    $context->when(CompiledResolverProduct::class)
        ->needs(CompiledResolverDependency::class)
        ->give(CompiledResolverAlternative::class);
    expect($context->getRepository()->getCompiledResolver('service'))->toBeNull();

    $environment = $newContainer('environment');
    $environment->setEnvironment('production');
    expect($environment->getRepository()->getCompiledResolver('service'))->toBeNull();

    $option = $newContainer('option');
    $option->enableLazyLoading(false);
    expect($option->getRepository()->getCompiledResolver('service'))->toBeNull();

    $resolver = $newContainer('resolver');
    $resolver->setResolverClass(InjectedCall::class);
    expect($resolver->getRepository()->getCompiledResolver('service'))->toBeNull();
});

it('generates deterministic artifacts regardless of definition registration order', function () {
    $first = Container::instance(uniqid('deterministic_first_'));
    $first->bind('a', CompiledResolverDependency::class);
    $first->bind('b', CompiledResolverAlternative::class);

    $second = Container::instance(uniqid('deterministic_second_'));
    $second->bind('b', CompiledResolverAlternative::class);
    $second->bind('a', CompiledResolverDependency::class);

    $firstPath = compiledResolverPath();
    $secondPath = compiledResolverPath();
    $first->compileTo($firstPath);
    $second->compileTo($secondPath);

    expect(file_get_contents($firstPath))->toBe(file_get_contents($secondPath));
});

it('validates staged output before atomically replacing an existing artifact', function () {
    $path = compiledResolverPath();
    AtomicFileWriter::write($path, 'known-good');

    expect(fn() => AtomicFileWriter::write(
        $path,
        'candidate',
        static function (): void {
            throw new RuntimeException('invalid staged output');
        },
    ))->toThrow(RuntimeException::class, 'invalid staged output')
        ->and(file_get_contents($path))->toBe('known-good');
});
