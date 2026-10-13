<?php

declare(strict_types=1);

use Infocyph\InterMix\DI\Build\StaticRuntimeArtifactMetadata;
use Infocyph\InterMix\DI\Build\StaticRuntimeGenerator;
use Infocyph\InterMix\DI\ContainerBuilder;
use Infocyph\InterMix\DI\Internal\ConfigurationContainer;
use Infocyph\InterMix\DI\Internal\ContainerAccess;
use Infocyph\InterMix\DI\RuntimeContainerInterface;
use Infocyph\InterMix\DI\Support\LifetimeEnum;
use Infocyph\InterMix\Exceptions\ContainerException;

final readonly class AutoBoundLogger
{
    /** Preserve the original value so conversion can be checked without scalar coercion. */
    public function __construct(public mixed $value = 'autowired') {}

    /** Convert a supplied binding without retaining execution values. */
    public static function fromBoundValue(mixed $value): static
    {
        if ($value === 'missing') {
            throw new DomainException('Logger binding was not found.');
        }

        return new self($value);
    }
}

final readonly class AutoBoundConsumer
{
    /** Exercise native and dynamic constructor parameter binding. */
    public function __construct(public AutoBoundLogger $logger) {}
}

final readonly class AutoBoundNullableConsumer
{
    /** Explicit null bindings remain null for nullable class parameters. */
    public function __construct(public ?AutoBoundLogger $logger) {}
}

final readonly class AutoBoundScalarConsumer
{
    /** A compatible primitive binding must work even when auto-binding is disabled. */
    public function __construct(public int $logger) {}
}

final readonly class AutoBoundDefaultScalarConsumer
{
    /** Incompatible literal bindings retain the same default in native and dynamic resolution. */
    public function __construct(public int $logger = 1) {}
}

final class AutoBoundMethodConsumer
{
    public ?AutoBoundLogger $seen = null;

    /** Explicit method argument attributes share the same conversion contract. */
    #[\Infocyph\InterMix\DI\Attribute\Inject(logger: 'identifier')]
    public function attributed(AutoBoundLogger $logger): AutoBoundLogger
    {
        return $logger;
    }

    /** Exercise native invocation recipes and runtime argument overrides. */
    public function run(AutoBoundLogger $logger): AutoBoundLogger
    {
        return $this->seen = $logger;
    }
}

final class AutoBoundWithoutConverter
{
    /** Classes without the conversion convention retain ordinary autowiring. */
    public function __construct(public string $value = 'autowired') {}
}

final class AutoBoundPureMethodConsumer
{
    /** Read a bound object without retaining it in a long-lived handler. */
    public function run(AutoBoundLogger $logger): mixed
    {
        return $logger->value;
    }
}

final class AutoBoundWrongResult
{
    /** An incompatible result must fail rather than falling back to autowiring. */
    public static function fromBoundValue(mixed $value): mixed
    {
        return $value;
    }
}

final class AutoBoundInstanceMethod
{
    /** Instance conversion methods cannot be used before constructing the target. */
    public function fromBoundValue(mixed $value): self
    {
        return $value instanceof self ? $value : $this;
    }
}

final class AutoBoundPrivateMethod
{
    private static function fromBoundValue(mixed $value): self
    {
        return $value instanceof self ? $value : new self();
    }
}

final class AutoBoundReferenceMethod
{
    /** Bound values are passed by value to prevent mutation of scope inputs. */
    public static function fromBoundValue(mixed &$value): self
    {
        return $value instanceof self ? $value : new self();
    }
}

/** Load compiled modes without retaining the builder or an original source graph. */
function autoBindingRuntime(ContainerBuilder $builder, string $mode, string $path): RuntimeContainerInterface
{
    if ($mode === 'dynamic') {
        return $builder->build();
    }

    $report = $builder->compile($path, strict: true);
    $manifest = json_decode(file_get_contents(dirname($report['artifact']) . '/manifest.json'), true, flags: JSON_THROW_ON_ERROR);
    expect($manifest['fallback']['required'])->toBeFalse();

    return $mode === 'compiled'
        ? ContainerBuilder::loadProductionArtifact($path, $report['graph'])
        : new StaticRuntimeGenerator()->loadPrevalidated($path, $report['digest']);
}

beforeEach(function () {
    $this->path = sys_get_temp_dir() . '/intermix-auto-binding-' . bin2hex(random_bytes(8)) . '.php';
});

afterEach(function () {
    if (is_link($this->path)) {
        unlink($this->path);
    }
    $root = $this->path . '.builds';
    if (!is_dir($root)) {
        return;
    }
    $entries = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST,
    );
    foreach ($entries as $entry) {
        $entry->isDir() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
    }
    rmdir($root);
});

it('converts named literal bindings only after auto-binding is enabled', function (string $mode) {
    $runtime = autoBindingRuntime(new ContainerBuilder()->enableAutoBind()->value('logger', 32)
        ->autowire('consumer', AutoBoundConsumer::class), $mode, $this->path);

    expect($runtime->get('consumer')->logger->value)->toBe(32);
})->with(['dynamic', 'compiled', 'prevalidated']);

it('keeps ordinary autowiring when auto-binding is disabled', function (string $mode) {
    $runtime = autoBindingRuntime(new ContainerBuilder()
        ->value('logger', 32)->autowire('consumer', AutoBoundConsumer::class), $mode, $this->path);

    expect($runtime->get('consumer')->logger->value)->toBe('autowired')
        ->and(fn() => $runtime->invoke(static fn(AutoBoundLogger $logger) => $logger, ['logger' => 32]))
        ->toThrow(TypeError::class);
})->with(['dynamic', 'compiled', 'prevalidated']);

it('converts explicit named and positional arguments and preserves compatible objects', function (string $mode) {
    $runtime = autoBindingRuntime(new ContainerBuilder()->enableAutoBind(), $mode, $this->path);
    $callback = static fn(AutoBoundLogger $logger): AutoBoundLogger => $logger;
    $object = new AutoBoundLogger(64);

    expect($runtime->invoke($callback, ['logger' => 32])->value)->toBe(32)
        ->and($runtime->invoke($callback, [0])->value)->toBe(0)
        ->and($runtime->invoke($callback, ['logger' => $object]))->toBe($object)
        ->and($runtime->make(AutoBoundConsumer::class, ['logger' => 17])->logger->value)->toBe(17);
})->with(['dynamic', 'compiled', 'prevalidated']);

it('preserves explicit and seeded nullable null values', function (string $mode) {
    $runtime = autoBindingRuntime(new ContainerBuilder()->enableAutoBind()->input('logger')
        ->autowire('consumer', AutoBoundNullableConsumer::class, lifetime: LifetimeEnum::Scoped), $mode, $this->path);

    expect($runtime->invoke(static fn(?AutoBoundLogger $logger) => $logger, ['logger' => null]))->toBeNull();
    $runtime->withinScope('null', function ($active) {
        expect($active->get('consumer')->logger)->toBeNull();
    }, ['logger' => null]);
})->with(['dynamic', 'compiled', 'prevalidated']);

it('converts scoped inputs without leaking between sequential or concurrent executions', function (string $mode) {
    $runtime = autoBindingRuntime(new ContainerBuilder()->enableAutoBind()->input('logger')
        ->autowire('consumer', AutoBoundConsumer::class, lifetime: LifetimeEnum::Scoped), $mode, $this->path);
    $first = $runtime->withinScope('request', fn($active) => $active->get('consumer'), ['logger' => 32]);
    $second = $runtime->withinScope('request', fn($active) => $active->get('consumer'), ['logger' => 64]);
    expect($first->logger->value)->toBe(32)->and($second->logger->value)->toBe(64)
        ->and($first->logger)->not->toBe($second->logger);

    $one = new Fiber(fn() => $runtime->withinScope('request', function ($active) {
        Fiber::suspend($active->get('consumer')->logger->value);
        return $active->get('consumer')->logger->value;
    }, ['logger' => 1]));
    $two = new Fiber(fn() => $runtime->withinScope('request', fn($active) => $active->get('consumer')->logger->value, ['logger' => 2]));
    expect($one->start())->toBe(1);
    $two->start();
    $one->resume();
    expect($one->getReturn())->toBe(1)->and($two->getReturn())->toBe(2);
    expect(fn() => $runtime->get('consumer'))->toThrow(ContainerException::class);
    expect(fn() => $runtime->withinScope('missing', fn($active) => $active->get('consumer')))
        ->toThrow(ContainerException::class, 'not supplied');
    expect(fn() => $runtime->withinScope('undeclared', static fn() => null, ['other' => 1]))
        ->toThrow(ContainerException::class, 'declared scoped');
})->with(['dynamic', 'compiled', 'prevalidated']);

it('rejects singleton capture of converted scoped input', function (string $mode) {
    $builder = new ContainerBuilder()->enableAutoBind()->input('logger')->autowire('consumer', AutoBoundConsumer::class);
    if ($mode === 'dynamic') {
        // Builder validation rejects captive dependencies before a runtime can be created.
        expect(fn() => $builder->build())->toThrow(ContainerException::class);
        return;
    }
    expect(fn() => $builder->compile($this->path, strict: true))->toThrow(ContainerException::class);
})->with(['dynamic', 'compiled', 'prevalidated']);

it('propagates conversion failures instead of silently autowiring another object', function (string $mode) {
    $runtime = autoBindingRuntime(new ContainerBuilder()->enableAutoBind(), $mode, $this->path);
    expect(fn() => $runtime->invoke(static fn(AutoBoundLogger $logger) => $logger, ['logger' => 'missing']))
        ->toThrow(DomainException::class, 'not found');
    expect(fn() => $runtime->invoke(static fn(AutoBoundWrongResult $logger) => $logger, ['logger' => 32]))
        ->toThrow(ContainerException::class, 'must return an instance');
})->with(['dynamic', 'compiled', 'prevalidated']);

it('rejects invalid conversion method contracts', function (string $class) {
    $runtime = new ContainerBuilder()->enableAutoBind()->build();
    $callback = match ($class) {
        AutoBoundInstanceMethod::class => static fn(AutoBoundInstanceMethod $logger) => $logger,
        AutoBoundPrivateMethod::class => static fn(AutoBoundPrivateMethod $logger) => $logger,
        default => static fn(AutoBoundReferenceMethod $logger) => $logger,
    };
    expect(fn() => $runtime->invoke($callback, ['logger' => 32]))
        ->toThrow(ContainerException::class, 'public, static');
})->with([AutoBoundInstanceMethod::class, AutoBoundPrivateMethod::class, AutoBoundReferenceMethod::class]);

it('passes compatible named primitive bindings without enabling auto-binding', function (string $mode) {
    $runtime = autoBindingRuntime(new ContainerBuilder()->value('logger', 32)
        ->autowire('consumer', AutoBoundScalarConsumer::class), $mode, $this->path);
    expect($runtime->get('consumer')->logger)->toBe(32)
        ->and($runtime->invoke(static fn(int $logger) => $logger))->toBe(32)
        ->and($runtime->invoke(static fn(int|string $logger) => $logger))->toBe(32);
})->with(['dynamic', 'compiled', 'prevalidated']);

it('supports the legacy options facade and rejects changes after locking', function () {
    $configuration = new ConfigurationContainer();
    $configuration->options()->enableAutoBind()->definitions()->bind('logger', 32);
    expect($configuration->invoke(static fn(AutoBoundLogger $logger) => $logger)->value)->toBe(32);
    $configuration->lock();
    expect(fn() => $configuration->options()->enableAutoBind(false))->toThrow(ContainerException::class, 'locked');
    $builder = new ContainerBuilder()->enableAutoBind();
    $runtime = $builder->build();
    expect(ContainerAccess::repository($runtime)->isAutoBindEnabled())->toBeTrue();
    expect(fn() => $builder->enableAutoBind(false))->toThrow(ContainerException::class);
});

it('includes auto-binding policy in graph identity and requires the new compiled ABI', function () {
    $enabled = new ContainerBuilder()->enableAutoBind()->value('same', 32);
    $report = $enabled->compile($this->path);
    $disabled = new ContainerBuilder()->value('same', 32);
    expect(fn() => $disabled->production($this->path))->toThrow(ContainerException::class, 'graph identity');
    $manifestPath = dirname($report['artifact']) . '/manifest.json';
    $manifest = json_decode(file_get_contents($manifestPath), true, flags: JSON_THROW_ON_ERROR);
    expect($manifest['abi'])->toBe(StaticRuntimeArtifactMetadata::ABI);
    $manifest['abi'] = 2;
    file_put_contents($manifestPath, json_encode($manifest, JSON_THROW_ON_ERROR));
    expect(fn() => ContainerBuilder::loadProductionArtifact($this->path, $report['graph']))
        ->toThrow(ContainerException::class);
});


it('converts explicit method invocation with registered native bindings', function (string $mode) {
    $runtime = autoBindingRuntime(new ContainerBuilder()->enableAutoBind()->value('logger', 32)
        ->autowire('handler', AutoBoundMethodConsumer::class), $mode, $this->path);
    $handler = $runtime->get('handler');
    expect($runtime->invoke([$handler, 'run'])->value)->toBe(32)
        ->and($runtime->invoke([$handler, 'run'], ['logger' => 64])->value)->toBe(64)
        ->and($runtime->invoke([$handler, 'run'], [0])->value)->toBe(0)
        ->and($runtime->invoke([$runtime->make(AutoBoundMethodConsumer::class), 'run'])->value)->toBe(32);
})->with(['dynamic', 'compiled', 'prevalidated']);

it('preserves classes without converters when enabled', function (string $mode) {
    $runtime = autoBindingRuntime(new ContainerBuilder()->enableAutoBind()->value('logger', 32), $mode, $this->path);
    expect($runtime->invoke(static fn(AutoBoundWithoutConverter $logger) => $logger)->value)->toBe('autowired');
    expect(fn() => $runtime->invoke(static fn(AutoBoundWithoutConverter $logger) => $logger, ['logger' => 32]))
        ->toThrow(TypeError::class);
})->with(['dynamic', 'compiled', 'prevalidated']);

it('defers native conversion until runtime and validates the returned object', function (string $mode) {
    $runtime = autoBindingRuntime(new ContainerBuilder()->enableAutoBind()->value('logger', 'missing')
        ->autowire('consumer', AutoBoundConsumer::class), $mode, $this->path);
    try {
        $runtime->get('consumer');
        test()->fail('Conversion must reject the missing binding.');
    } catch (DomainException|ContainerException $exception) {
        $cause = $mode === 'dynamic' ? $exception->getPrevious() : $exception;
        expect($cause)->toBeInstanceOf(DomainException::class)
            ->and($cause->getMessage())->toContain('not found');
    }
})->with(['dynamic', 'compiled', 'prevalidated']);

it('keeps primitive type compatibility independent of class conversion', function () {
    $configuration = new ConfigurationContainer();
    $configuration->options()->enableAutoBind();
    $definitions = $configuration->definitions();
    foreach ([['value' => false, 'callback' => static fn(bool $value) => $value],
        ['value' => 0, 'callback' => static fn(int $value) => $value],
        ['value' => 1, 'callback' => static fn(float $value) => $value],
        ['value' => [], 'callback' => static fn(iterable $value) => $value],
        ['value' => '32', 'callback' => static fn(string $value) => $value],
        ['value' => null, 'callback' => static fn(?int $value) => $value]] as $case) {
        $definitions->bind('value', $case['value']);
        expect($configuration->invoke($case['callback']))->toBe($case['value'] === 1 ? 1.0 : $case['value']);
    }
});

it('applies native conversion to legacy invocation recipes without requiring fallback', function () {
    $configuration = new ConfigurationContainer();
    $configuration->options()->enableAutoBind();
    $configuration->definitions()->bind('logger', 32)->bind('handler', [AutoBoundMethodConsumer::class, 'run']);
    $graph = \Infocyph\InterMix\DI\Build\DefinitionGraph::from(ContainerAccess::repository($configuration));
    $generator = new StaticRuntimeGenerator();
    $report = $generator->generate($graph, $this->path);
    $runtime = $generator->loadCompiled($this->path, $report['graph'], null);
    expect($runtime->get('handler')->value)->toBe(32);

    $fresh = new ReflectionMethod($runtime, 'freshCompiledInvocationWithParameters');
    $result = null;
    $arguments = [AutoBoundMethodConsumer::class, 'run', ['logger' => 64], &$result];
    expect($fresh->invokeArgs($runtime, $arguments))->toBeTrue()->and($result->value)->toBe(64);
    $arguments = [AutoBoundMethodConsumer::class, 'run', [0], &$result];
    expect($fresh->invokeArgs($runtime, $arguments))->toBeTrue()->and($result->value)->toBe(0);
});

it('converts contextual literal bindings consistently across runtimes', function (string $mode) {
    $builder = new ContainerBuilder()->enableAutoBind()->autowire('consumer', AutoBoundConsumer::class);
    $builder->when(AutoBoundConsumer::class)->needs(AutoBoundLogger::class)->giveValue(32);
    $runtime = autoBindingRuntime($builder, $mode, $this->path);
    expect($runtime->get('consumer')->logger->value)->toBe(32);
})->with(['dynamic', 'compiled', 'prevalidated']);

it('converts method argument attribute targets in runtime invocations', function (string $mode) {
    $runtime = autoBindingRuntime(new ContainerBuilder()->enableAutoBind()->enableMethodAttributes()->value('identifier', 32)
        ->autowire('handler', AutoBoundMethodConsumer::class), $mode, $this->path);
    expect($runtime->invoke([$runtime->get('handler'), 'attributed'])->value)->toBe(32);
})->with(['dynamic', 'compiled', 'prevalidated']);

it('converts class-named inputs and releases converted objects when their scope closes', function (string $mode) {
    $runtime = autoBindingRuntime(new ContainerBuilder()->enableAutoBind()->input(AutoBoundLogger::class)
        ->autowire('handler', AutoBoundPureMethodConsumer::class)
        ->autowire('consumer', AutoBoundConsumer::class, lifetime: LifetimeEnum::Scoped), $mode, $this->path);
    foreach (['request', 'job', 'scheduler'] as $scope) {
        $reference = $runtime->withinScope($scope, function ($active) {
            $logger = $active->get('consumer')->logger;
            expect($logger->value)->toBe(32)
                ->and($active->invoke([$active->get('handler'), 'run']))->toBe(32);
            return WeakReference::create($logger);
        }, [AutoBoundLogger::class => 32]);
        expect($reference->get())->toBeNull();
    }
})->with(['dynamic', 'compiled', 'prevalidated']);


it('preserves compatible named bindings when a typed native dependency also exists', function (string $mode) {
    $runtime = autoBindingRuntime(new ContainerBuilder()
        ->autowire(AutoBoundLogger::class, AutoBoundLogger::class, ['value' => 64])
        ->autowire('logger', AutoBoundLogger::class, ['value' => 32])
        ->autowire('handler', AutoBoundPureMethodConsumer::class), $mode, $this->path);
    expect($runtime->get(AutoBoundLogger::class)->value)->toBe(64)
        ->and($runtime->invoke([$runtime->get('handler'), 'run']))->toBe(32);
})->with(['dynamic', 'compiled', 'prevalidated']);


it('keeps default primitive resolution consistent when a named literal is incompatible', function (string $mode) {
    $runtime = autoBindingRuntime(new ContainerBuilder()->value('logger', 'invalid')
        ->autowire('consumer', AutoBoundDefaultScalarConsumer::class), $mode, $this->path);
    expect($runtime->get('consumer')->logger)->toBe(1);
})->with(['dynamic', 'compiled', 'prevalidated']);
