<?php

declare(strict_types=1);

use Infocyph\CacheLayer\Cache\Cache;
use Infocyph\InterMix\DI\Container;
use Infocyph\InterMix\DI\ContainerBuilder;
use Infocyph\InterMix\DI\Support\FactoryDefinition;
use Infocyph\InterMix\DI\Support\LifetimeEnum;
use Infocyph\InterMix\DI\Support\ServiceProviderInterface;
use Infocyph\InterMix\Exceptions\ContainerException;

final class Builder11Singleton {}

final class Builder11Transient {}

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
        ->toThrow(ContainerException::class, "Required scoped input 'request.id' was not supplied.");
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
    $definitions = $builder->definitions();

    $first = $builder->build();
    $second = $builder->build();

    expect($first->get('singleton'))->toBe($first->get('singleton'))
        ->and($second->get('singleton'))->toBe($second->get('singleton'))
        ->and($first->get('singleton'))->not->toBe($second->get('singleton'))
        ->and(fn() => $builder->value('late', true))
        ->toThrow(ContainerException::class, 'ContainerBuilder is finalized')
        ->and(fn() => $definitions->bind('late', true))
        ->toThrow(ContainerException::class, 'Container is locked');
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

    expect(fn() => $builder->compile($path))->toThrow(RuntimeException::class)
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
        ->and(str_starts_with($first->getRepository()->makeDefinitionCacheKey('cached'), 'imx11.'))
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
