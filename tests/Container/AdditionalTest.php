<?php

use Infocyph\InterMix\DI\Container;
use Infocyph\InterMix\DI\Support\DebugTracer;
use Infocyph\InterMix\DI\Support\LifetimeEnum;
use Infocyph\InterMix\DI\Support\PreloadGenerator;
use Infocyph\InterMix\DI\Support\TraceLevelEnum;
use Infocyph\InterMix\Tests\Fixture\DemoProvider;
use Infocyph\InterMix\Tests\Fixture\DummyLogger;
use Infocyph\InterMix\Tests\Fixture\FooService;
use Infocyph\InterMix\Tests\Fixture\ListenerA;
use Infocyph\InterMix\Tests\Fixture\ListenerB;
use Infocyph\InterMix\Tests\Fixture\PaymentGateway;
use Infocyph\InterMix\Tests\Fixture\PaypalGateway;
use Infocyph\InterMix\Tests\Fixture\StripeGateway;

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
