<?php

use Infocyph\InterMix\DI\Container;
use Infocyph\InterMix\DI\Support\DebugTracer;
use Infocyph\InterMix\DI\Support\LifetimeEnum;
use Infocyph\InterMix\DI\Support\PreloadGenerator;
use Infocyph\InterMix\DI\Support\ReflectionResource;
use Infocyph\InterMix\DI\Support\TraceLevelEnum;
use Infocyph\InterMix\Exceptions\ContainerException;
use Infocyph\InterMix\Tests\Fixture\DemoProvider;
use Infocyph\InterMix\Tests\Fixture\DummyLogger;
use Infocyph\InterMix\Tests\Fixture\FooService;
use Infocyph\InterMix\Tests\Fixture\ListenerA;
use Infocyph\InterMix\Tests\Fixture\ListenerB;
use Infocyph\InterMix\Tests\Fixture\PaymentGateway;
use Infocyph\InterMix\Tests\Fixture\PaypalGateway;
use Infocyph\InterMix\Tests\Fixture\StripeGateway;
use Psr\Cache\CacheItemInterface;
use Psr\Cache\CacheItemPoolInterface;

class FailingMakeTarget
{
    public function required(string $value): string
    {
        return $value;
    }
}

interface ContextLogger
{
    public function channel(): string;
}

class OrderLogger implements ContextLogger
{
    public function channel(): string
    {
        return 'order';
    }
}

class BillingLogger implements ContextLogger
{
    public function channel(): string
    {
        return 'billing';
    }
}

class OrderService
{
    public function __construct(public ContextLogger $logger) {}
}

class BillingService
{
    public function __construct(public ContextLogger $logger) {}
}

class LazyTaggedProbe
{
    public function __construct(public int $id) {}
}

class CompiledDep {}

class CompiledSvc
{
    public function __construct(public CompiledDep $dep) {}
}

class SpyCacheItem implements CacheItemInterface
{
    public function __construct(
        private readonly string $key,
        private mixed $value = null,
        private bool $hit = false,
    ) {}

    public function getKey(): string
    {
        return $this->key;
    }

    public function get(): mixed
    {
        return $this->value;
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

    public function expiresAfter(int|\DateInterval|null $time): static
    {
        return $this;
    }

    public function expiresAt(?\DateTimeInterface $expiration): static
    {
        return $this;
    }
}

class SpyCachePool implements CacheItemPoolInterface
{
    /** @var array<string, SpyCacheItem> */
    public array $items = [];

    /** @var array<int, mixed> */
    public array $savedValues = [];

    public function getItem(string $key): CacheItemInterface
    {
        if (!isset($this->items[$key])) {
            $this->items[$key] = new SpyCacheItem($key, null, false);
        }

        return $this->items[$key];
    }

    public function getItems(array $keys = []): iterable
    {
        $items = [];
        foreach ($keys as $key) {
            if (is_string($key)) {
                $items[$key] = $this->getItem($key);
            }
        }

        return $items;
    }

    public function hasItem(string $key): bool
    {
        return isset($this->items[$key]) && $this->items[$key]->isHit();
    }

    public function clear(): bool
    {
        $this->items = [];
        $this->savedValues = [];

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
            if (is_string($key)) {
                unset($this->items[$key]);
            }
        }

        return true;
    }

    public function save(CacheItemInterface $item): bool
    {
        $stored = new SpyCacheItem($item->getKey(), $item->get(), true);
        $this->items[$item->getKey()] = $stored;
        $this->savedValues[] = $item->get();

        return true;
    }

    public function saveDeferred(CacheItemInterface $item): bool
    {
        return $this->save($item);
    }

    public function commit(): bool
    {
        return true;
    }
}

it('collects a readable trace', function () {
    $c = Container::instance('intermix')->options()->enableDebugTracing();
    $c->definitions()->bind(FooService::class, fn () => new FooService());

    $trace = $c->end()->debug(FooService::class);

    expect($trace)->toBeArray()
        ->and($trace[1]['msg'])->toContain('def:' . FooService::class);
});

it('switches concrete by environment', function () {
    $c = Container::instance('intermix')
        ->options()->setEnvironment('prod')
        ->registration()->end();

    $c->options()->bindInterfaceForEnv('prod', PaymentGateway::class, StripeGateway::class);
    $c->options()->bindInterfaceForEnv('test', PaymentGateway::class, PaypalGateway::class);

    $gw = $c->make(PaymentGateway::class);

    expect($gw)->toBeInstanceOf(StripeGateway::class)
        ->and($gw->pay(10))->toBe('stripe:10');
});

it('defers initialisation when lazy-loading is enabled', function () {
    $flag = false;
    $c = Container::instance('intermix')->options()->enableLazyLoading();

    $c->definitions()->bind('heavy', function () use (&$flag) {
        $flag = true;
        return 123;
    });

    expect($flag)->toBeFalse();          // nothing executed yet
    $val = $c->end()->get('heavy');
    expect($flag)->toBeTrue()->and($val)->toBe(123);
});

it('honours singleton vs transient vs scoped lifetimes', function () {
    $c = Container::instance('intermix');

    // singleton
    $c->definitions()->bind('uniq', fn () => new stdClass(), LifetimeEnum::Singleton);
    expect($c->get('uniq'))->toBe($c->get('uniq'));

    // transient
    $c->definitions()->bind('trans', fn () => new stdClass(), LifetimeEnum::Transient);
    expect($c->get('trans'))->not->toBe($c->get('trans'));

    // scoped
    $c->definitions()->bind('scoped', fn () => new stdClass(), LifetimeEnum::Scoped);
    $first = $c->get('scoped');

    $c->getRepository()->setScope('next-request');
    $second = $c->get('scoped');

    expect($first)->not->toBe($second);
});

it('stores resolved entries only through lifetime-aware keys', function () {
    $c = Container::instance(uniqid('lifetime_keys_'));
    $repo = $c->getRepository();

    $c->definitions()->bind('svc.singleton', fn () => new stdClass(), LifetimeEnum::Singleton);
    $c->definitions()->bind('svc.scoped', fn () => new stdClass(), LifetimeEnum::Scoped);
    $c->definitions()->bind('svc.transient', fn () => new stdClass(), LifetimeEnum::Transient);

    $c->enterScope('request-a');
    $c->get('svc.singleton');
    $c->get('svc.scoped');
    $c->get('svc.transient');

    expect($repo->hasResolved('svc.singleton'))->toBeTrue()
        ->and($repo->hasResolved('svc.scoped'))->toBeFalse()
        ->and($repo->hasResolved('svc.scoped@request-a'))->toBeTrue()
        ->and($repo->hasResolved('svc.transient'))->toBeFalse()
        ->and($repo->hasResolved('svc.transient@request-a'))->toBeFalse();

    $c->leaveScope();
});

it('reads scoped getReturn values from the current scope key', function () {
    $c = Container::instance(uniqid('scope_return_'));
    $repo = $c->getRepository();
    $c->definitions()->bind('scope.return', fn () => new stdClass(), LifetimeEnum::Scoped);
    $repo->setResolved('scope.return', ['instance' => new stdClass(), 'returned' => 'stale-base']);

    $repo->setScope('request-1');
    $repo->setResolvedScoped('request-1', 'scope.return@request-1', ['instance' => new stdClass(), 'returned' => 'scope-1']);
    $first = $c->getReturn('scope.return');

    $repo->setScope('request-2');
    $repo->setResolvedScoped('request-2', 'scope.return@request-2', ['instance' => new stdClass(), 'returned' => 'scope-2']);
    $second = $c->getReturn('scope.return');

    expect($first)->toBe('scope-1')
        ->and($second)->toBe('scope-2');
});

it('factory binding is deferred until lifetime selection', function () {
    $c = Container::instance(uniqid('factory_deferred_'));
    $repo = $c->getRepository();

    $pending = $c->factory(
        'factory.db',
        static fn(Container $container): stdClass => $container->get(stdClass::class),
    );

    expect($repo->hasFunctionReference('factory.db'))->toBeFalse();

    $pending->scoped();

    expect($repo->hasFunctionReference('factory.db'))->toBeTrue()
        ->and($repo->getDefinitionLifetime('factory.db'))->toBe(LifetimeEnum::Scoped);
});

it('offers the same sugar directly on DefinitionManager', function () {
    $c   = Container::instance(uniqid('mp_'));
    $def = $c->definitions();

    // (1) property assignment on the manager
    $def->fooSvc = fn () => new stdClass();

    // (2) array assignment
    $def['barSvc'] = fn () =>  'BAR';

    // ------------- resolve through *container* -------------
    expect($c->fooSvc)
        ->toBeInstanceOf(stdClass::class)
        ->and($c->barSvc)->toBe('BAR')
        ->and($def('fooSvc'))->toBe($c->fooSvc)
        ->and($def->barSvc)->toBe('BAR')
        ->and($def['fooSvc'])->toBeInstanceOf(stdClass::class);

    // ------------- resolve directly through manager -------------
    // ArrayAccess on manager

    // (3) fluent chain keeps working and ends back on Container
    $result = $def
        ->bind('answer', fn () => 42)
        ->options()
        ->enableLazyLoading()
        ->end();

    expect($result)->toBe($c)
        ->and($c->answer)->toBe(42);
});

it('generates a preload list', function () {
    $c = Container::instance('intermix');
    $file = sys_get_temp_dir() . '/_preload.php';
    (new PreloadGenerator())->generate($c, $file);

    expect(is_file($file))->toBeTrue()
        ->and(file_get_contents($file))->toContain('require_once');
});

it('isolates resolved instances per scope', function () {
    $c = Container::instance('intermix');
    $c->definitions()->bind('obj', fn () => new stdClass(), lifetime: Infocyph\InterMix\DI\Support\LifetimeEnum::Scoped);

    $a = $c->get('obj');
    $c->getRepository()->setScope('child');
    $b = $c->get('obj');

    expect($a)->not->toBe($b);
});

it('imports a service provider', function () {
    $c = Container::instance('intermix');
    $c->registration()->import(DemoProvider::class);

    expect($c->get(FooService::class))->toBeInstanceOf(FooService::class);
});

it('supports property / array / callable sugar on the container', function () {
    /** fresh alias so each run is isolated */
    $c = Container::instance(uniqid('cs_'));

    // (1) property assignment → definition
    $c->logger = fn () => new DummyLogger();

    // (2) array assignment → definition
    $c['cfg'] = fn () => ['debug' => true, 'dsn' => 'mysql://dummy'];

    // ---------- retrieval paths ----------
    $viaCallObject   = $c('logger');   // __invoke
    $viaMagicGet     = $c->logger;     // __get
    $viaArrayGet     = $c['logger'];   // ArrayAccess

    expect($viaCallObject)
        ->toBeInstanceOf(DummyLogger::class)
        ->and($viaMagicGet)->toBe($viaCallObject)
        ->and($viaArrayGet)->toBe($viaCallObject)
        ->and($c('cfg'))
        ->toHaveKey('debug', true)
        ->and($c['cfg'])->toBe($c('cfg'))
        ->and($c->cfg)->toBe($c('cfg'));
});

it('collects services by tag', function () {
    $c = Container::instance('intermix');

    $c->definitions()->bind('A', fn () => new ListenerA(), tags: ['event']);
    $c->definitions()->bind('B', fn () => new ListenerB(), tags: ['event']);

    $all = $c->findByTag('event');   // method defined in Container :contentReference[oaicite:0]{index=0}
    expect($all)->toHaveCount(2)
        ->and($all['A']())->toBe('A')
        ->and($all['B']())->toBe('B');
});

it('lets me wire and use services in one-liners', function () {
    $c = Container::instance(uniqid('e2e_'));

    $c->logger = fn () => new DummyLogger();
    $c['now'] = fn () => new DateTimeImmutable();

    // the manager can re-use them transparently
    $def = $c->definitions();
    $def->greeter = function () use ($c) {
        $c->logger->log('greeted');
        return 'Hello @ ' . $c->now->format('c');
    };

    $msg = $c->greeter;

    expect($msg)
        ->toStartWith('Hello @ ')
        ->and($c->logger->records)->toHaveCount(1);
});

it('applies environment-scoped lifetime/tag overrides', function () {
    $c = Container::instance(uniqid('env_meta_'));

    $c->definitions()->bind('svc', fn () => new stdClass(), LifetimeEnum::Singleton, ['base']);
    $c->options()
        ->setDefinitionMetaForEnv('test', 'svc', LifetimeEnum::Transient, ['env-only'])
        ->setEnvironment('test')
        ->end();

    $a = $c->get('svc');
    $b = $c->get('svc');

    expect($a)->not->toBe($b)
        ->and($c->findByTag('env-only'))->toHaveKey('svc')
        ->and($c->findByTag('base'))->not->toHaveKey('svc');
});

it('supports first-class scope lifecycle API', function () {
    $c = Container::instance(uniqid('scope_api_'));
    $c->definitions()->bind('scoped_obj', fn () => new stdClass(), LifetimeEnum::Scoped);

    $first = $c->enterScope('req-1')->get('scoped_obj');
    $same = $c->get('scoped_obj');

    $c->leaveScope();
    $second = $c->enterScope('req-2')->get('scoped_obj');

    expect($first)->toBe($same)
        ->and($first)->not->toBe($second);
});

it('restores parent scope after nested withinScope', function () {
    $c = Container::instance(uniqid('nested_scope_'));
    $c->definitions()->bind('scoped_obj', fn () => new stdClass(), LifetimeEnum::Scoped);

    $outerFirst = $c->enterScope('outer')->get('scoped_obj');
    $inner = $c->withinScope('inner', fn (Container $scoped) => $scoped->get('scoped_obj'));
    $outerAgain = $c->get('scoped_obj');

    expect($inner)->not->toBe($outerFirst)
        ->and($outerAgain)->toBe($outerFirst);
});

it('isolates singleton definition resolution cache per environment', function () {
    $c = Container::instance(uniqid('env_isolation_'));

    $c->options()
        ->bindInterfaceForEnv('prod', PaymentGateway::class, StripeGateway::class)
        ->bindInterfaceForEnv('test', PaymentGateway::class, PaypalGateway::class)
        ->setEnvironment('prod')
        ->end();
    $c->definitions()->bind('gateway', fn () => $c->make(PaymentGateway::class), LifetimeEnum::Singleton);

    $prodA = $c->get('gateway');
    $prodB = $c->get('gateway');

    $c->setEnvironment('test');
    $testA = $c->get('gateway');
    $testB = $c->get('gateway');

    $c->setEnvironment('prod');
    $prodC = $c->get('gateway');

    expect($prodA)->toBeInstanceOf(StripeGateway::class)
        ->and($prodA)->toBe($prodB)
        ->and($testA)->toBeInstanceOf(PaypalGateway::class)
        ->and($testA)->toBe($testB)
        ->and($testA)->not->toBe($prodA)
        ->and($prodC)->toBeInstanceOf(StripeGateway::class)
        ->and($prodC)->not->toBe($prodA);
});

it('exports a dependency graph from tracer instrumentation', function () {
    $c = Container::instance(uniqid('graph_'));
    $c->options()->enableDebugTracing(true, TraceLevelEnum::Verbose)->end();

    $c->definitions()->bind('dep', fn () => new stdClass());
    $c->definitions()->bind('root', fn () => $c->get('dep'));

    $c->get('root');
    $graph = $c->exportGraph(clear: true);

    $hasEdge = false;
    foreach ($graph['edges'] as $edge) {
        if ($edge['from'] === 'root' && $edge['to'] === 'dep') {
            $hasEdge = true;
            break;
        }
    }

    expect($graph['nodes'])->toContain('root', 'dep')
        ->and($hasEdge)->toBeTrue();
});

it('accumulates dependency edge counts and clears graph state', function () {
    $c = Container::instance(uniqid('graph_counts_'));
    $c->options()->enableDebugTracing(true, TraceLevelEnum::Verbose)->end();

    $c->definitions()->bind('dep', fn () => new stdClass(), LifetimeEnum::Transient);
    $c->definitions()->bind('root', fn () => $c->get('dep'), LifetimeEnum::Transient);

    $c->get('root');
    $c->get('root');

    $graph = $c->exportGraph();
    $edgeCount = null;
    foreach ($graph['edges'] as $edge) {
        if ($edge['from'] === 'root' && $edge['to'] === 'dep') {
            $edgeCount = $edge['count'];
            break;
        }
    }

    $c->exportGraph(clear: true);
    $empty = $c->exportGraph();

    expect($edgeCount)->toBe(2)
        ->and($empty['nodes'])->toBe([])
        ->and($empty['edges'])->toBe([]);
});

it('restores tracer configuration after debug inspection', function () {
    $c = Container::instance(uniqid('debug_restore_'));
    $tracer = $c->tracer();
    $tracer->setLevel(TraceLevelEnum::Off);
    $tracer->setCaptureLocation(false);

    $c->debug('missing.service');

    expect($tracer->level())->toBe(TraceLevelEnum::Off)
        ->and($tracer->isCaptureLocationEnabled())->toBeFalse();
});

it('does not leak resolved-resource state when make() fails', function () {
    $c = Container::instance(uniqid('make_leak_'));

    expect(fn () => $c->make(FailingMakeTarget::class, 'required'))
        ->toThrow(ContainerException::class);

    $resolved = $c->getRepository()->getResolvedResource();

    expect(array_key_exists(FailingMakeTarget::class, $resolved))->toBeFalse()
        ->and($c->getRepository()->getResolvedResourceFor(FailingMakeTarget::class))->toBe([]);
});

it('allows class-string self binding and rejects scalar self aliases', function () {
    $c = Container::instance(uniqid('self_bind_'));
    $c->definitions()->bind(CompiledDep::class, CompiledDep::class);

    expect($c->get(CompiledDep::class))->toBeInstanceOf(CompiledDep::class);

    expect(fn () => $c->definitions()->bind('foo', 'foo'))
        ->toThrow(ContainerException::class);
});

it('does not persist runtime objects to PSR-6 definition cache by default', function () {
    $pool = new SpyCachePool();
    $c = Container::instance(uniqid('cache_safe_'));
    $c->definitions()->enableDefinitionCache($pool);
    $c->definitions()->bind('unsafe.obj', fn () => new stdClass());
    $c->definitions()->bind('safe.cfg', ['a' => 1, 'b' => ['c' => 2]]);

    $c->get('unsafe.obj');
    $c->get('safe.cfg');

    expect($pool->savedValues)->toHaveCount(1)
        ->and($pool->savedValues[0])->toBe(['a' => 1, 'b' => ['c' => 2]]);
});

it('validates callable parsing for malformed method strings', function () {
    $c = Container::instance(uniqid('callable_parse_'));

    expect(fn () => $c->parseCallable('Foo@'))->toThrow(ContainerException::class);
    expect(fn () => $c->parseCallable('@bar'))->toThrow(ContainerException::class);
    expect(fn () => $c->parseCallable('Foo::'))->toThrow(ContainerException::class);
    expect(fn () => $c->parseCallable('::bar'))->toThrow(ContainerException::class);
});

it('validates class and method existence in callable parsing', function () {
    $c = Container::instance(uniqid('callable_exists_'));

    expect(fn () => $c->parseCallable('Missing\\CallableClass@handle'))
        ->toThrow(ContainerException::class, 'does not exist');

    expect(fn () => $c->parseCallable(CompiledSvc::class . '::missingMethod'))
        ->toThrow(ContainerException::class, 'does not exist');
});

it('supports contextual binding per consumer', function () {
    $c = Container::instance(uniqid('contextual_'));
    $c->when(OrderService::class)->needs(ContextLogger::class)->give(OrderLogger::class);
    $c->when(BillingService::class)->needs(ContextLogger::class)->give(BillingLogger::class);

    $order = $c->make(OrderService::class);
    $billing = $c->make(BillingService::class);

    expect($order->logger->channel())->toBe('order')
        ->and($billing->logger->channel())->toBe('billing');
});

it('runs lifecycle hooks for resolving and resolved services', function () {
    $c = Container::instance(uniqid('hooks_'));
    $events = [];

    $c->onResolving('hook.svc', function (string $id) use (&$events): void {
        $events[] = "resolving:$id";
    });
    $c->onResolved('hook.svc', function (string $id) use (&$events): void {
        $events[] = "resolved:$id";
    });
    $c->definitions()->bind('hook.svc', fn () => new stdClass(), LifetimeEnum::Transient);

    $c->get('hook.svc');

    expect($events)->toBe(['resolving:hook.svc', 'resolved:hook.svc']);
});

it('runs scope-leave lifecycle hooks', function () {
    $c = Container::instance(uniqid('hook_scope_'));
    $events = [];

    $c->onScopeLeave('request', function (string $scope) use (&$events): void {
        $events[] = "left:$scope";
    });

    $c->enterScope('request');
    $c->leaveScope();

    expect($events)->toBe(['left:request']);
});

it('returns lazy tagged resolvers without eager instantiation', function () {
    $c = Container::instance(uniqid('tag_lazy_'));
    $built = 0;
    $c->definitions()->bind('lazy.a', function () use (&$built) {
        $built++;

        return new LazyTaggedProbe(1);
    }, tags: ['lazy.tag']);

    $lazy = $c->tagged('lazy.tag');
    expect($built)->toBe(0);

    foreach ($lazy as $factory) {
        $svc = $factory();
        expect($svc)->toBeInstanceOf(LazyTaggedProbe::class);
    }

    expect($built)->toBe(1);
});

it('can generate and load compiled resolver maps', function () {
    $path = sys_get_temp_dir() . '/intermix_compiled_' . uniqid() . '.php';

    $source = Container::instance(uniqid('compiled_src_'));
    $source->definitions()->bind('compiled.dep', CompiledDep::class);
    $source->definitions()->bind('compiled.svc', CompiledSvc::class);
    $source->compileTo($path);
    expect($source->getRepository()->getCompiledResolver('compiled.svc'))->toBeNull();

    $source->compileTo($path, load: true);
    expect($source->getRepository()->getCompiledResolver('compiled.svc'))->toBeCallable();

    $target = Container::instance(uniqid('compiled_tgt_'));
    $target->definitions()->bind('compiled.dep', CompiledDep::class);
    $target->definitions()->bind('compiled.svc', CompiledSvc::class);
    $target->useCompiled($path);

    expect($target->get('compiled.svc'))->toBeInstanceOf(CompiledSvc::class);
});

it('supports bounded reflection cache controls', function () {
    ReflectionResource::clearCache();
    ReflectionResource::setCacheLimit(1);

    ReflectionResource::getClassReflection(stdClass::class);
    ReflectionResource::getClassReflection(DateTimeImmutable::class);
    $stats = ReflectionResource::cacheStats();

    expect($stats['classes'])->toBeLessThanOrEqual(1)
        ->and($stats['limit'])->toBe(1);

    ReflectionResource::setCacheLimit(0);
    ReflectionResource::clearCache();
});

it('provides explicit container validation output', function () {
    $c = Container::instance(uniqid('validate_'));
    $c->definitions()->bind('broken.target', 'Missing\\NotFoundClass');

    $issues = $c->validate();

    expect($issues)->not()->toBeEmpty();
    expect(fn () => $c->validate(strict: true))->toThrow(ContainerException::class);
});

function newTracer(
    TraceLevelEnum $level = TraceLevelEnum::Node,
    bool $captureLocation = false,
): DebugTracer {
    return new DebugTracer($level, $captureLocation);
}

it('does not record when level is Off', function () {
    $t = newTracer(TraceLevelEnum::Off);

    $t->push('should be ignored');
    expect($t->getEntries())->toHaveCount(0);
});

it('records a push() at Node level', function () {
    $t = newTracer();

    $t->push('hello world');
    $entries = $t->getEntries();

    expect($entries)->toHaveCount(1)
        ->and($entries[0]->message)->toBe('hello world')
        ->and($entries[0]->level)->toBe(TraceLevelEnum::Node);
});

it('filters out Verbose below current threshold', function () {
    $t = newTracer(TraceLevelEnum::Node);   // default threshold

    $t->push('verbose', TraceLevelEnum::Verbose);
    expect($t->getEntries())->toBeEmpty();
});

it('begins and ends a span with a returned closure', function () {
    $t = newTracer(TraceLevelEnum::Node);

    $close = $t->beginSpan('compile-container');   // returns Closure
    expect($close)
        ->toBeInstanceOf(Closure::class)
        ->and($t->getEntries())->toHaveCount(1)
        ->and($t->getEntries()[0]->message)
        ->toContain('start: compile-container');

    // span should be in the active list now

    // close the span
    $close();

    $entries = $t->getEntries();
    expect($entries)->toHaveCount(2)
        // second entry is the “end” record
        ->and($entries[1]->message)->toContain('end: compile-container');
});

it('toArray() adds Δus between subsequent events', function () {
    $t = newTracer(TraceLevelEnum::Node);

    $t->push('first');
    usleep(500);          // 0.5 ms
    $t->push('second');

    $arr = $t->toArray();

    expect($arr)
        ->toHaveCount(2)
        ->and($arr[0]['Δus'])->toBe(0)
        ->and($arr[1]['Δus'])->toBeGreaterThan(0)
        ->and($t->getEntries())->toBeEmpty();
});
