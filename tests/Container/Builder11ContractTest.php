<?php

declare(strict_types=1);

use Infocyph\CacheLayer\Cache\Cache;
use Infocyph\InterMix\DI\Container;
use Infocyph\InterMix\DI\ContainerBuilder;
use Infocyph\InterMix\DI\Internal\ContainerAccess;
use Infocyph\InterMix\DI\Support\FactoryDefinition;
use Infocyph\InterMix\DI\Support\LifetimeEnum;
use Infocyph\InterMix\DI\Support\ServiceProviderInterface;
use Infocyph\InterMix\Exceptions\ContainerException;

final class Builder11Singleton {}

final class Builder11Transient {}

interface Builder11ContextContract {}

final class Builder11ContextDefault implements Builder11ContextContract {}

final class Builder11ContextAlternate implements Builder11ContextContract {}

final class Builder11ContextConsumer
{
    public function __construct(public Builder11ContextContract $dependency) {}
}

final class Builder11Configured
{
    public string $label = 'default';

    public function __construct(public int $port = 80) {}
}

final class Builder11Provider implements ServiceProviderInterface
{
    public function __construct(private readonly string $value) {}

    public function register(ContainerBuilder $builder): void
    {
        $builder->value('provided', $this->value);
    }
}

final class Builder11LiteralTarget
{
    public static function make(): self
    {
        return new self();
    }
}

function builder11ArtifactPath(): string
{
    return sys_get_temp_dir() . '/intermix-builder11-' . bin2hex(random_bytes(8)) . '.php';
}

function removeBuilder11Artifact(string $path): void
{
    foreach ([$path, $path . '.meta.json'] as $artifact) {
        if (is_file($artifact)) {
            unlink($artifact);
        }
    }
}

it('keeps explicit values literal even when they look executable', function (): void {
    $closure = static fn(): string => 'executed';
    $callable = [Builder11LiteralTarget::class, 'make'];

    $runtime = ContainerBuilder::create(uniqid('builder11_literal_'))
        ->value('closure', $closure)
        ->value('callable', $callable)
        ->value('class-name', Builder11LiteralTarget::class)
        ->build();

    expect($runtime->get('closure'))->toBe($closure)
        ->and($runtime->get('callable'))->toBe($callable)
        ->and($runtime->get('class-name'))->toBe(Builder11LiteralTarget::class);
});

it('executes only explicit factories and supplies the runtime', function (): void {
    $seen = null;

    $runtime = ContainerBuilder::create(uniqid('builder11_factory_'))
        ->factory(
            'factory',
            static function (Container $runtime) use (&$seen): object {
                $seen = $runtime;

                return new stdClass();
            },
            LifetimeEnum::Transient,
        )
        ->build();

    $first = $runtime->get('factory');
    $second = $runtime->get('factory');

    expect($seen)->toBe($runtime)
        ->and($first)->toBeInstanceOf(stdClass::class)
        ->and($second)->toBeInstanceOf(stdClass::class)
        ->and($first)->not->toBe($second);
});

it('applies explicit autowire constructor and property overrides', function (): void {
    $runtime = ContainerBuilder::create(uniqid('builder11_autowire_'))
        ->autowire(
            'configured',
            Builder11Configured::class,
            arguments: ['port' => 443],
            properties: ['label' => 'secure'],
        )
        ->build();

    $configured = $runtime->get('configured');

    expect($configured)->toBeInstanceOf(Builder11Configured::class)
        ->and($configured->port)->toBe(443)
        ->and($configured->label)->toBe('secure');
});

it('imports supplied providers through the builder contract', function (): void {
    $runtime = ContainerBuilder::create(uniqid('builder11_provider_'))
        ->import(new Builder11Provider('provider-value'))
        ->build();

    expect($runtime->get('provided'))->toBe('provider-value');
});

it('requires explicit scoped inputs and returns the supplied seed', function (): void {
    $runtime = ContainerBuilder::create(uniqid('builder11_input_'))
        ->input('request.id')
        ->build();

    expect(
        $runtime->withinScope(
            'request',
            static fn(Container $runtime): mixed => $runtime->get('request.id'),
            ['request.id' => 'req-42'],
        ),
    )->toBe('req-42')
        ->and(fn() => $runtime->get('request.id'))
        ->toThrow(ContainerException::class, "Scoped entry 'request.id' requires an active scope.");
});

it('makes aliases follow target identity and lifetime without alias caching', function (): void {
    $singletonRuntime = ContainerBuilder::create(uniqid('builder11_alias_singleton_'))
        ->autowire('target', Builder11Singleton::class)
        ->alias('alias', 'target')
        ->build();

    expect($singletonRuntime->get('alias'))->toBe($singletonRuntime->get('target'));

    $transientRuntime = ContainerBuilder::create(uniqid('builder11_alias_transient_'))
        ->autowire('target', Builder11Transient::class, lifetime: LifetimeEnum::Transient)
        ->alias('alias', 'target')
        ->build();

    expect($transientRuntime->get('alias'))->not->toBe($transientRuntime->get('alias'));
});

it('rejects duplicate explicit registration until the id is unbound', function (): void {
    $builder = ContainerBuilder::create(uniqid('builder11_duplicate_'))
        ->value('answer', 41);

    expect(fn() => $builder->value('answer', 42))
        ->toThrow(ContainerException::class, "Definition 'answer' is already registered");

    $runtime = $builder
        ->unbind('answer')
        ->value('answer', 42)
        ->build();

    expect($runtime->get('answer'))->toBe(42);
});

it('freezes configuration while allowing isolated runtimes from one builder', function (): void {
    $builder = ContainerBuilder::create(uniqid('builder11_freeze_'))
        ->autowire('singleton', Builder11Singleton::class);

    $first = $builder->build();
    $second = $builder->build();

    expect($first->get('singleton'))->toBe($first->get('singleton'))
        ->and($second->get('singleton'))->toBe($second->get('singleton'))
        ->and($first->get('singleton'))->not->toBe($second->get('singleton'))
        ->and(fn() => $builder->value('late', true))
        ->toThrow(ContainerException::class, 'ContainerBuilder is finalized');
});

it('keeps the builder mutable when configuration validation fails', function (): void {
    $builder = ContainerBuilder::create(uniqid('builder11_validation_'))
        ->alias('broken', 'missing');

    expect(fn() => $builder->build())
        ->toThrow(ContainerException::class, "targets undeclared service 'missing'");

    $runtime = $builder
        ->unbind('broken')
        ->value('recovered', true)
        ->build();

    expect($runtime->get('recovered'))->toBeTrue();
});

it('stays frozen when artifact publication fails after graph finalization', function (): void {
    $builder = ContainerBuilder::create(uniqid('builder11_artifact_'))
        ->value('answer', 42);
    $path = sys_get_temp_dir()
        . '/intermix-builder11-missing-'
        . bin2hex(random_bytes(8))
        . '/runtime.php';

    expect(fn() => $builder->compile($path))->toThrow(
        ContainerException::class,
        'Output directory does not exist',
    )
        ->and(fn() => $builder->value('late', true))
        ->toThrow(ContainerException::class, 'ContainerBuilder is finalized');
});

it('uses explicit cache eligibility with an InterMix 11 namespace key', function (): void {
    $cache = Cache::memory('builder11.' . bin2hex(random_bytes(4)));
    $cachedRuns = 0;
    $uncachedRuns = 0;

    $builder = ContainerBuilder::create(uniqid('builder11_cache_'))
        ->definitionCache($cache, 'application-a', 'release-1')
        ->factory(
            'cached',
            static function () use (&$cachedRuns): int {
                return ++$cachedRuns;
            },
        )
        ->cacheDefinition('cached')
        ->factory(
            'uncached',
            static function () use (&$uncachedRuns): int {
                return ++$uncachedRuns;
            },
        );

    $first = $builder->build();
    expect($first->get('cached'))->toBe(1)
        ->and($first->get('uncached'))->toBe(1)
        ->and(str_starts_with(ContainerAccess::repository($first)->makeDefinitionCacheKey('cached'), 'imx11.'))
        ->toBeTrue();

    $second = $builder->build();
    expect($second->get('cached'))->toBe(1)
        ->and($cachedRuns)->toBe(1)
        ->and($second->get('uncached'))->toBe(2)
        ->and($uncachedRuns)->toBe(2);
});

it('rejects external cache eligibility for non-factory definitions at build time', function (): void {
    $cache = Cache::memory('builder11.invalid.' . bin2hex(random_bytes(4)));
    $builder = ContainerBuilder::create(uniqid('builder11_invalid_cache_'))
        ->definitionCache($cache, 'application-a', 'release-1')
        ->value('literal', 42)
        ->cacheDefinition('literal');

    expect(fn() => $builder->build())
        ->toThrow(ContainerException::class, "is not an explicit factory");
});

it('compiles literal class and callable-looking values without reinterpretation', function (): void {
    $path = builder11ArtifactPath();
    $callable = [Builder11LiteralTarget::class, 'make'];
    $builder = ContainerBuilder::create(uniqid('builder11_compiled_literal_'))
        ->value('class-name', Builder11LiteralTarget::class)
        ->value('callable', $callable);

    try {
        $report = $builder->compile($path);
        $runtime = $builder->production($path);

        expect($report['compiled'])->toContain('class-name', 'callable')
            ->and($runtime->get('class-name'))->toBe(Builder11LiteralTarget::class)
            ->and($runtime->get('callable'))->toBe($callable);
    } finally {
        removeBuilder11Artifact($path);
    }
});

it('keeps compiled aliases cache-free for transient targets', function (): void {
    $path = builder11ArtifactPath();
    $builder = ContainerBuilder::create(uniqid('builder11_compiled_alias_'))
        ->factory(
            'target',
            FactoryDefinition::construct(Builder11Transient::class),
            LifetimeEnum::Transient,
        )
        ->alias('alias', 'target');

    try {
        $builder->compile($path);
        $runtime = $builder->production($path);

        expect($runtime->get('alias'))->not->toBe($runtime->get('alias'));
    } finally {
        removeBuilder11Artifact($path);
    }
});

it('rejects strict compilation before publishing unsupported definitions', function (): void {
    $path = builder11ArtifactPath();
    $builder = ContainerBuilder::create(uniqid('builder11_strict_'))
        ->factory('runtime-only', static fn(): object => new stdClass());

    try {
        expect(fn() => $builder->compile($path, true))
            ->toThrow(ContainerException::class, 'Strict static compilation rejected unsupported definitions')
            ->and(is_file($path))->toBeFalse()
            ->and(fn() => $builder->value('late', true))
            ->toThrow(ContainerException::class, 'ContainerBuilder is finalized');
    } finally {
        removeBuilder11Artifact($path);
    }
});


it('uses explicit contextual binding kinds without mixed dispatch', function (): void {
    $literal = new Builder11ContextAlternate();

    $classRuntime = ContainerBuilder::create(uniqid('builder11_context_class_'))
        ->autowire('consumer', Builder11ContextConsumer::class, lifetime: LifetimeEnum::Transient)
        ->when(Builder11ContextConsumer::class)
        ->needs(Builder11ContextContract::class)
        ->giveClass(Builder11ContextDefault::class)
        ->build();

    $valueRuntime = ContainerBuilder::create(uniqid('builder11_context_value_'))
        ->autowire('consumer', Builder11ContextConsumer::class, lifetime: LifetimeEnum::Transient)
        ->when(Builder11ContextConsumer::class)
        ->needs(Builder11ContextContract::class)
        ->giveValue($literal)
        ->build();

    $referenceRuntime = ContainerBuilder::create(uniqid('builder11_context_reference_'))
        ->autowire('alternate', Builder11ContextAlternate::class)
        ->autowire('consumer', Builder11ContextConsumer::class, lifetime: LifetimeEnum::Transient)
        ->when(Builder11ContextConsumer::class)
        ->needs(Builder11ContextContract::class)
        ->giveReference('alternate')
        ->build();

    $factoryRuntime = ContainerBuilder::create(uniqid('builder11_context_factory_'))
        ->autowire('consumer', Builder11ContextConsumer::class, lifetime: LifetimeEnum::Transient)
        ->when(Builder11ContextConsumer::class)
        ->needs(Builder11ContextContract::class)
        ->giveFactory(static fn(): Builder11ContextContract => new Builder11ContextAlternate())
        ->build();

    expect($classRuntime->get('consumer')->dependency)->toBeInstanceOf(Builder11ContextDefault::class)
        ->and($valueRuntime->get('consumer')->dependency)->toBe($literal)
        ->and($referenceRuntime->get('consumer')->dependency)->toBe($referenceRuntime->get('alternate'))
        ->and($factoryRuntime->get('consumer')->dependency)->toBeInstanceOf(Builder11ContextAlternate::class);
});

it('snapshots autowire metadata without retaining writable array references', function (): void {
    $port = 443;
    $label = 'secure';
    $arguments = ['port' => &$port];
    $properties = ['label' => &$label];

    $builder = ContainerBuilder::create(uniqid('builder11_snapshot_'))
        ->autowire(
            'configured',
            Builder11Configured::class,
            arguments: $arguments,
            properties: $properties,
        );

    $port = 80;
    $label = 'mutated';

    $configured = $builder->build()->get('configured');

    expect($configured->port)->toBe(443)
        ->and($configured->label)->toBe('secure');
});

it('warms only explicitly eligible definition-cache entries through the builder', function (): void {
    $cache = Cache::memory('builder11.warm.' . bin2hex(random_bytes(4)));
    $cachedRuns = 0;
    $uncachedRuns = 0;

    $builder = ContainerBuilder::create(uniqid('builder11_warm_'))
        ->definitionCache($cache, 'application-a', 'release-1')
        ->factory('cached', static function () use (&$cachedRuns): int {
            return ++$cachedRuns;
        })
        ->cacheDefinition('cached')
        ->factory('uncached', static function () use (&$uncachedRuns): int {
            return ++$uncachedRuns;
        });

    $report = $builder->warmDefinitionCache();
    $runtime = $builder->build();

    expect($report['written'])->toBe(1)
        ->and($runtime->get('cached'))->toBe(1)
        ->and($cachedRuns)->toBe(1)
        ->and($runtime->get('uncached'))->toBe(1)
        ->and($uncachedRuns)->toBe(1);
});

it('separates definition-cache keys by explicit namespace and generation', function (): void {
    $cache = Cache::memory('builder11.keys.' . bin2hex(random_bytes(4)));

    $first = ContainerBuilder::create(uniqid('builder11_key_a_'))
        ->definitionCache($cache, 'application-a', 'release-1')
        ->factory('cached', static fn(): int => 1)
        ->cacheDefinition('cached')
        ->build();

    $second = ContainerBuilder::create(uniqid('builder11_key_b_'))
        ->definitionCache($cache, 'application-b', 'release-1')
        ->factory('cached', static fn(): int => 1)
        ->cacheDefinition('cached')
        ->build();

    $third = ContainerBuilder::create(uniqid('builder11_key_c_'))
        ->definitionCache($cache, 'application-a', 'release-2')
        ->factory('cached', static fn(): int => 1)
        ->cacheDefinition('cached')
        ->build();

    $firstKey = ContainerAccess::repository($first)->makeDefinitionCacheKey('cached');
    $secondKey = ContainerAccess::repository($second)->makeDefinitionCacheKey('cached');
    $thirdKey = ContainerAccess::repository($third)->makeDefinitionCacheKey('cached');

    $equivalent = ContainerBuilder::create(uniqid('builder11_key_equivalent_'))
        ->factory('other', static fn(): int => 2)
        ->definitionCache($cache, 'application-a', 'release-1')
        ->factory('cached', static fn(): int => 1)
        ->cacheDefinition('cached')
        ->build();
    $equivalentKey = ContainerAccess::repository($equivalent)->makeDefinitionCacheKey('cached');

    expect($firstKey)->not->toBe($secondKey)
        ->and($firstKey)->not->toBe($thirdKey)
        ->and($equivalentKey)->toBe($firstKey)
        ->and(str_starts_with($firstKey, 'imx11.'))->toBeTrue();
});


it('snapshots declarative factory argument arrays without writable references', function (): void {
    $port = 443;
    $arguments = [['port' => &$port]];
    $definition = FactoryDefinition::construct(Builder11Configured::class, $arguments);

    $port = 80;

    expect($definition->arguments[0]['port'])->toBe(443);
});


it('accepts canonical numeric-string service id zero in dynamic and compiled runtimes', function (): void {
    $dynamic = ContainerBuilder::create(uniqid('builder11_zero_dynamic_'))
        ->value('0', 'zero')
        ->build();

    expect($dynamic->has('0'))->toBeTrue()
        ->and($dynamic->get('0'))->toBe('zero');

    $path = builder11ArtifactPath();
    $builder = ContainerBuilder::create(uniqid('builder11_zero_compiled_'))
        ->value('0', 'zero');

    try {
        $report = $builder->compile($path);
        $runtime = $builder->production($path);

        expect($report['compiled'])->toContain('0')
            ->and($runtime->has('0'))->toBeTrue()
            ->and($runtime->get('0'))->toBe('zero');
    } finally {
        removeBuilder11Artifact($path);
    }
});


it('keeps configuration ownership on the builder without manager escape hatches', function (): void {
    $constructor = new ReflectionMethod(ContainerBuilder::class, '__construct');
    $parameter = $constructor->getParameters()[0] ?? null;

    expect($parameter)->not->toBeNull()
        ->and((string) $parameter?->getType())->toBe('string')
        ->and(method_exists(ContainerBuilder::class, 'bind'))->toBeFalse()
        ->and(method_exists(ContainerBuilder::class, 'bindFactory'))->toBeFalse()
        ->and(method_exists(ContainerBuilder::class, 'singleton'))->toBeFalse()
        ->and(method_exists(ContainerBuilder::class, 'scoped'))->toBeFalse()
        ->and(method_exists(ContainerBuilder::class, 'transient'))->toBeFalse()
        ->and(method_exists(ContainerBuilder::class, 'definitions'))->toBeFalse()
        ->and(method_exists(ContainerBuilder::class, 'registration'))->toBeFalse()
        ->and(method_exists(ContainerBuilder::class, 'options'))->toBeFalse()
        ->and(method_exists(ContainerBuilder::class, 'development'))->toBeFalse();
});


it('reuses safe opted-in values across equivalent explicit cache generations', function (): void {
    $cache = Cache::memory('builder11.shared.' . bin2hex(random_bytes(4)));
    $firstRuns = 0;
    $secondRuns = 0;

    $first = ContainerBuilder::create(uniqid('builder11_shared_first_'))
        ->definitionCache($cache, 'application-a', 'release-shared')
        ->factory('cached', static function () use (&$firstRuns): int {
            ++$firstRuns;

            return 41;
        })
        ->cacheDefinition('cached')
        ->build();

    expect($first->get('cached'))->toBe(41)
        ->and($firstRuns)->toBe(1);

    $second = ContainerBuilder::create(uniqid('builder11_shared_second_'))
        ->factory('unrelated', static fn(): int => 99)
        ->definitionCache($cache, 'application-a', 'release-shared')
        ->factory('cached', static function () use (&$secondRuns): int {
            ++$secondRuns;

            return 42;
        })
        ->cacheDefinition('cached')
        ->build();

    expect($second->get('cached'))->toBe(41)
        ->and($secondRuns)->toBe(0);
});
