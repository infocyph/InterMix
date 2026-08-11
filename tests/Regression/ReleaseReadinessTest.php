<?php

declare(strict_types=1);

use Infocyph\InterMix\DI\Attribute\Inject;
use Infocyph\InterMix\DI\Container;
use Infocyph\InterMix\DI\Invoker;
use Infocyph\InterMix\DI\Invoker\GenericCall;
use Infocyph\InterMix\DI\Support\DebugTracer;
use Infocyph\InterMix\DI\Support\ServiceProviderInterface;
use Infocyph\InterMix\DI\Support\TraceLevelEnum;
use Infocyph\InterMix\Exceptions\ContainerException;
use Infocyph\InterMix\Exceptions\NotFoundException;
use Infocyph\InterMix\Fence\Fence;
use Infocyph\InterMix\Internal\AtomicFileWriter;
use Infocyph\InterMix\Remix\MacroMix;
use Infocyph\InterMix\Serializer\ClosureSerializer;
use Psr\Cache\CacheItemInterface;
use Psr\Cache\CacheItemPoolInterface;

final class ReleaseMethodTarget
{
    public function nullable(): null
    {
        return null;
    }

    public function value(): string
    {
        return 'method-value';
    }
}

final class ReleaseCtorTarget
{
    public function __construct(public mixed $value) {}
}

interface ReleaseA {}
interface ReleaseB {}
interface ReleaseC {}
final class ReleaseAB implements ReleaseA, ReleaseB {}
final class ReleaseOnlyC implements ReleaseC {}

final class ReleaseIntersectionConsumer
{
    public function __construct(public ReleaseA&ReleaseB $dependency) {}
}

final class ReleaseDnfConsumer
{
    public function __construct(public (ReleaseA&ReleaseB)|ReleaseC $dependency) {}
}

final class ReleaseNullableConsumer
{
    public function __construct(public ?ReleaseA $dependency) {}
}

interface ReleasePropertyContract {}
final class ReleasePropertyImplementation implements ReleasePropertyContract {}

final class ReleaseStaticProperty
{
    #[Inject]
    public static ReleasePropertyContract $dependency;
}

final class ReleaseGlobalProperty
{
    #[Inject]
    public ReleasePropertyContract $dependency;
}

class ReleaseGrandparent
{
    #[Inject('grandparent.value')]
    private string $grandparentValue = '';

    public function grandparentValue(): string
    {
        return $this->grandparentValue;
    }
}

class ReleaseParent extends ReleaseGrandparent {}
final class ReleaseChild extends ReleaseParent {}

final class ReleaseNullProperty
{
    public ?string $value = 'original';
}

final class ReleaseMethodAttribute
{
    #[Inject(retries: 2, enabled: true, nothing: null, options: ['safe' => true])]
    public function values(int $retries, bool $enabled, mixed $nothing, array $options): array
    {
        return [
            'retries' => $retries,
            'enabled' => $enabled,
            'nothing' => $nothing,
            'options' => $options,
        ];
    }
}

final class ReleaseProviderSideEffectMixin
{
    public static int $constructed = 0;

    public function __construct()
    {
        ++self::$constructed;
    }

    public static function ping(): string
    {
        return 'pong';
    }
}

final class ReleaseRequiredConstructorProvider implements ServiceProviderInterface
{
    public function __construct(string $required) {}

    public function register(Container $container): void {}
}

final class ReleaseMacroHost
{
    use MacroMix;

    public const ENABLE_LOCK = true;
}

final class ReleaseFence
{
    use Fence;

    public const int FENCE_LIMIT = 1;
}

final class ReleaseCacheItem implements CacheItemInterface
{
    public function __construct(
        private readonly string $key,
        private mixed $value = null,
        private bool $hit = false,
    ) {}

    public function expiresAfter(DateInterval|int|null $time): static
    {
        return $this;
    }

    public function expiresAt(?DateTimeInterface $expiration): static
    {
        return $this;
    }

    public function get(): mixed
    {
        return $this->value;
    }

    public function getKey(): string
    {
        return $this->key;
    }

    public function isHit(): bool
    {
        return $this->hit;
    }

    public function set(mixed $value): static
    {
        $this->value = $value;

        return $this;
    }
}

final class ReleaseCachePool implements CacheItemPoolInterface
{
    public int $clearCalls = 0;

    /** @var array<string, ReleaseCacheItem> */
    public array $items = [];

    public function clear(): bool
    {
        ++$this->clearCalls;
        $this->items = [];

        return true;
    }

    public function commit(): bool
    {
        return true;
    }

    public function deleteItem(string $key): bool
    {
        unset($this->items[$key]);

        return true;
    }

    public function deleteItems(array $keys): bool
    {
        foreach ($keys as $key) {
            unset($this->items[$key]);
        }

return true;
    }

    public function getItem(string $key): CacheItemInterface
    {
        return $this->items[$key] ??= new ReleaseCacheItem($key);
    }

    public function getItems(array $keys = []): iterable
    {
        foreach ($keys as $key) {
            yield $key => $this->getItem($key);
        }
    }

    public function hasItem(string $key): bool
    {
        return ($this->items[$key] ?? null)?->isHit() ?? false;
    }

    public function save(CacheItemInterface $item): bool
    {
        $this->items[$item->getKey()] = new ReleaseCacheItem($item->getKey(), $item->get(), true);

        return true;
    }

    public function saveDeferred(CacheItemInterface $item): bool
    {
        return $this->save($item);
    }
}

function releaseContainer(string $suffix): Container
{
    return new Container('release-' . $suffix . '-' . uniqid());
}

it('never confuses user arrays with class resolution state', function () {
    $container = releaseContainer('arrays');
    $container->value('instance-array', ['instance' => 'production']);
    $container->value('returned-array', ['returned' => false]);

    expect($container->get('instance-array'))->toBe(['instance' => 'production'])
        ->and($container->getReturn('returned-array'))->toBe(['returned' => false]);
});

it('returns exact explicit method results and rejects missing methods', function () {
    $container = releaseContainer('methods');

    expect($container->call(ReleaseMethodTarget::class, 'value'))->toBe('method-value')
        ->and($container->call(ReleaseMethodTarget::class, 'nullable'))->toBeNull()
        ->and(fn() => $container->call(ReleaseMethodTarget::class, 'missing'))
        ->toThrow(ContainerException::class);
});

it('keeps PSR has and get semantics consistent', function () {
    $container = releaseContainer('psr');

    expect($container->has(ReleaseMethodTarget::class))->toBeTrue()
        ->and($container->get(ReleaseMethodTarget::class))->toBeInstanceOf(ReleaseMethodTarget::class)
        ->and($container->has('release.missing'))->toBeFalse()
        ->and(fn() => $container->get('release.missing'))->toThrow(NotFoundException::class);
});

it('invalidates singleton scoped and class registration state', function () {
    $container = releaseContainer('invalidate');
    $container->value('value', 1);
    expect($container->get('value'))->toBe(1);
    $container->value('value', 2);
    expect($container->get('value'))->toBe(2);

    $container->scoped('scoped', static fn() => (object) ['version' => 1]);
    $container->enterScope('request');
    $first = $container->get('scoped');
    $container->scoped('scoped', static fn() => (object) ['version' => 2]);
    expect($container->get('scoped'))->not->toBe($first)->version->toBe(2);
    $container->leaveScope();

    $container->registration()->registerClass(ReleaseCtorTarget::class, ['value' => 1]);
    expect($container->get(ReleaseCtorTarget::class)->value)->toBe(1);
    $container->registration()->registerClass(ReleaseCtorTarget::class, ['value' => 2]);
    expect($container->get(ReleaseCtorTarget::class)->value)->toBe(2);
});

it('namespaces persistent cache and never clears or stores runtime objects', function () {
    $pool = new ReleaseCachePool();
    $pool->save(new ReleaseCacheItem('unrelated', 'keep', true));
    $container = releaseContainer('cache');
    $container->definitions()->enableDefinitionCache($pool, 'deployment-a');
    $container->bind('safe', static fn() => 'one');
    expect($container->get('safe'))->toBe('one');
    $firstKeys = array_keys($pool->items);

    $container->bind('safe', static fn() => 'two');
    expect($container->get('safe'))->toBe('two');
    $container->bind('object', static fn() => new stdClass());
    $container->get('object');
    $container->definitions()->warmDefinitionCache(true);

    expect(array_keys($pool->items))->not->toBe($firstKeys)
        ->and($pool->clearCalls)->toBe(0)
        ->and($pool->hasItem('unrelated'))->toBeTrue();
    foreach ($pool->items as $item) {
        expect($item->get())->not->toBeInstanceOf(stdClass::class);
    }
});

it('supports contextual null, intersections, and DNF alternatives', function () {
    $null = releaseContainer('context-null');
    $null->when(ReleaseNullableConsumer::class)->needs(ReleaseA::class)->give(null);
    expect($null->get(ReleaseNullableConsumer::class)->dependency)->toBeNull();

    $intersection = releaseContainer('intersection');
    $intersection->bind(ReleaseA::class, ReleaseAB::class);
    expect($intersection->get(ReleaseIntersectionConsumer::class)->dependency)->toBeInstanceOf(ReleaseAB::class);

    $dnf = releaseContainer('dnf');
    $dnf->bind(ReleaseC::class, ReleaseOnlyC::class);
    expect($dnf->get(ReleaseDnfConsumer::class)->dependency)->toBeInstanceOf(ReleaseOnlyC::class);
});

it('gives explicit named and positional arguments first precedence', function () {
    $container = releaseContainer('precedence');
    $container->value('value', 'definition');
    $invoker = Invoker::with($container);

    expect($invoker->make(ReleaseCtorTarget::class, ['value' => 'named'])->value)->toBe('named')
        ->and($invoker->make(ReleaseCtorTarget::class, ['positional'])->value)->toBe('positional');
});

it('keeps same-line closure plans distinct', function () {
    $container = releaseContainer('closures');
    $container->bind(ReleaseA::class, ReleaseAB::class);
    $container->bind(ReleaseC::class, ReleaseOnlyC::class);
    $closures = [fn(ReleaseA $value) => $value, fn(ReleaseC $value) => $value];

    expect($container->resolveNow($closures[0]))->toBeInstanceOf(ReleaseAB::class)
        ->and($container->resolveNow($closures[1]))->toBeInstanceOf(ReleaseOnlyC::class);
});

it('uses structural scope keys and rejects duplicate active names', function () {
    $container = releaseContainer('scope-keys');
    $repository = $container->getRepository();
    $repository->setResolvedScoped('c', 'a@b', 'first');
    $repository->setResolvedScoped('b@c', 'a', 'second');

    expect($repository->getResolvedScopedEntry('c', 'a@b'))->toBe('first')
        ->and($repository->getResolvedScopedEntry('b@c', 'a'))->toBe('second');

    $container->enterScope('request');
    expect(fn() => $container->enterScope('request'))->toThrow(ContainerException::class);
    $container->leaveScope();
});

it('does not let an isolated duplicate alias unset the registered owner', function () {
    $alias = 'release-owner-' . uniqid();
    $owner = Container::instance($alias);
    $isolated = new Container($alias);
    $isolated->unset();

    expect(Container::instance($alias))->toBe($owner);
    $owner->unset();
});

it('keeps Invoker and resolveNow arguments ephemeral', function () {
    $container = releaseContainer('ephemeral');
    $container->registration()->registerClass(ReleaseCtorTarget::class, ['value' => 'permanent']);
    $before = $container->getRepository()->getClassResourceFor(ReleaseCtorTarget::class);

    expect(Invoker::with($container)->make(ReleaseCtorTarget::class, ['value' => 'one-off'])->value)->toBe('one-off')
        ->and($container->resolveNow(ReleaseCtorTarget::class, ['value' => 'now'])->value)->toBe('now')
        ->and($container->getRepository()->getClassResourceFor(ReleaseCtorTarget::class))->toBe($before)
        ->and($container->make(ReleaseCtorTarget::class)->value)->toBe('permanent');
});

it('invokes explicit methods on definition IDs', function () {
    $container = releaseContainer('definition-method');
    $container->bind('service', ReleaseMethodTarget::class);

    expect($container->call('service', 'value'))->toBe('method-value')
        ->and(fn() => $container->call(static fn() => 'value', 'value'))
        ->toThrow(ContainerException::class);
});

it('validates provider classes before construction', function () {
    ReleaseProviderSideEffectMixin::$constructed = 0;
    $container = releaseContainer('providers');

    expect(fn() => $container->registration()->import(ReleaseProviderSideEffectMixin::class))
        ->toThrow(ContainerException::class)
        ->and(ReleaseProviderSideEffectMixin::$constructed)->toBe(0)
        ->and(fn() => $container->registration()->import(ReleaseRequiredConstructorProvider::class))
        ->toThrow(ContainerException::class, 'zero arguments');
});

it('normalizes service IDs and makes offset unset remove definitions', function () {
    $container = releaseContainer('service-ids');
    $stringable = new class implements Stringable {
        public function __toString(): string
        {
            return 'stringable';
        }
    };

    $container[7] = 'integer';
    $container[$stringable] = 'object';
    $container['remove-me'] = 'value';
    unset($container['remove-me']);

    expect($container->get('7'))->toBe('integer')
        ->and($container->get('stringable'))->toBe('object')
        ->and(fn() => $container->get('remove-me'))->toThrow(NotFoundException::class)
        ->and(fn() => $container[false] = 'invalid')->toThrow(InvalidArgumentException::class);
});

it('resolves all property injection forms across inheritance', function () {
    $container = releaseContainer('properties');
    $container->options()->setOptions(propertyAttributes: true);
    $container->bind(ReleasePropertyContract::class, ReleasePropertyImplementation::class);
    $container->value('grandparent.value', 'ancestor');
    $container->registration()->registerProperty(ReleaseNullProperty::class, ['value' => null]);

    $container->get(ReleaseStaticProperty::class);
    expect(ReleaseStaticProperty::$dependency)->toBeInstanceOf(ReleasePropertyImplementation::class)
        ->and($container->get(ReleaseGlobalProperty::class)->dependency)->toBeInstanceOf(ReleasePropertyImplementation::class)
        ->and($container->get(ReleaseChild::class)->grandparentValue())->toBe('ancestor')
        ->and($container->get(ReleaseNullProperty::class)->value)->toBeNull();
});

it('preserves mixed method-level Inject values', function () {
    $container = releaseContainer('mixed-attribute');
    $container->options()->setOptions(methodAttributes: true);

    expect($container->call(ReleaseMethodAttribute::class, 'values'))->toBe([
        'retries' => 2,
        'enabled' => true,
        'nothing' => null,
        'options' => ['safe' => true],
    ]);
});

it('locks attribute resolver resolver hook and option mutation', function () {
    $container = releaseContainer('lock');
    $container->lock();

    expect(fn() => $container->setResolverClass(GenericCall::class))->toThrow(ContainerException::class)
        ->and(fn() => $container->onResolved('x', static fn() => null))->toThrow(ContainerException::class)
        ->and(fn() => $container->enableLazyLoading(false))->toThrow(ContainerException::class)
        ->and(fn() => $container->attributeRegistry()->register(Inject::class, stdClass::class))
        ->toThrow(ContainerException::class);
});

it('does not expose private Container methods through magic calls', function () {
    $container = releaseContainer('proxy');

    expect(fn() => $container->parseClassMethodParts('strlen', '::'))->toThrow(Error::class);
});

it('enforces explicit and bounded Closure deserialization', function () {
    $payload = ClosureSerializer::serialize(static fn() => 'safe');
    $invoker = Invoker::with(releaseContainer('serializer'));

    expect(fn() => $invoker->invoke($payload))->toThrow(InvalidArgumentException::class)
        ->and(fn() => ClosureSerializer::unserialize($payload, 4))->toThrow(InvalidArgumentException::class)
        ->and(fn() => ClosureSerializer::signed('key', 10)->unserialize(str_repeat('x', 11)))
        ->toThrow(InvalidArgumentException::class);

    $signed = ClosureSerializer::signed('key')->serialize(static fn() => 'signed');
    $badLength = 'imxcs1.' . base64_encode('short') . '.' . explode('.', $signed, 3)[2];
    expect(fn() => ClosureSerializer::signed('key')->unserialize($badLength))
        ->toThrow(InvalidArgumentException::class)
        ->and(fn() => ClosureSerializer::signed('other')->unserialize($signed))
        ->toThrow(InvalidArgumentException::class);
});

it('keeps MacroMix construction and mutation boundaries explicit', function () {
    ReleaseProviderSideEffectMixin::$constructed = 0;
    ReleaseMacroHost::mix(ReleaseProviderSideEffectMixin::class);
    expect(ReleaseProviderSideEffectMixin::$constructed)->toBe(0)
        ->and(ReleaseMacroHost::ping())->toBe('pong')
        ->and(fn() => ReleaseMacroHost::macro('bad', new stdClass()))->toThrow(TypeError::class);

    ReleaseMacroHost::macro('instance-only', function (): string {
        return 'bound';
    });
    expect(fn() => ReleaseMacroHost::instanceOnly())->toThrow(BadMethodCallException::class);

    $date = new DateTimeImmutable();
    ReleaseMacroHost::macro('unboundInternal', $date->format(...));
    expect(fn() => (new ReleaseMacroHost())->unboundInternal('c'))
        ->toThrow(BadMethodCallException::class, 'Unable to bind');

    $mutate = new ReflectionMethod(ReleaseMacroHost::class, 'mutate');
    expect(fn() => $mutate->invoke(null, static fn() => throw new RuntimeException('failed')))
        ->toThrow(RuntimeException::class, 'failed');
});

it('validates Fence requirements and captures real trace diagnostics', function () {
    ReleaseFence::reset();
    expect(fn() => ReleaseFence::instance(constraints: ['classes' => 'stdClass']))
        ->toThrow(InvalidArgumentException::class);

    $tracer = new DebugTracer(TraceLevelEnum::Node);
    $close = $tracer->beginSpan('active');
    $entries = $tracer->getEntries();
    expect($entries)->toHaveCount(2)
        ->and($entries[0]->memory)->toBeGreaterThan(0)
        ->and($entries[0]->hrtime)->toBeGreaterThan(0)
        ->and($entries[1]->message)->toContain('end: active');
    $close();
});

it('throws for invalid tagged pipeline services', function () {
    $container = releaseContainer('pipeline');
    $container->bind('invalid.pipe', new stdClass(), tags: ['pipeline']);

    expect(fn() => $container->pipeline('pipeline')->send('value')->thenReturn())
        ->toThrow(ContainerException::class, "Tagged service 'invalid.pipe'");
});

it('activates generated files with explicit readable permissions', function () {
    $path = sys_get_temp_dir() . '/intermix-atomic-' . uniqid() . '.php';

    try {
        AtomicFileWriter::write($path, '<?php return true;');
        expect(fileperms($path) & 0777)->toBe(0644);
    } finally {
        if (is_file($path)) {
            unlink($path);
        }
    }
});
