<?php

use Infocyph\InterMix\DI\Container;
use Infocyph\InterMix\DI\Support\Lifetime;
use Infocyph\InterMix\DI\Support\PreloadGenerator;
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
    $c->definitions()->bind('uniq', fn () => new stdClass(), Lifetime::Singleton);
    expect($c->get('uniq'))->toBe($c->get('uniq'));

    // transient
    $c->definitions()->bind('trans', fn () => new stdClass(), Lifetime::Transient);
    expect($c->get('trans'))->not->toBe($c->get('trans'));

    // scoped
    $c->definitions()->bind('scoped', fn () => new stdClass(), Lifetime::Scoped);
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
    $c->definitions()->bind('obj', fn () => new stdClass(), lifetime: Infocyph\InterMix\DI\Support\Lifetime::Scoped);

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
