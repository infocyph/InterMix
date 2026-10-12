<?php

declare(strict_types=1);

use Infocyph\InterMix\DI\Build\StaticRuntimeGenerator;
use Infocyph\InterMix\DI\ContainerBuilder;
use Infocyph\InterMix\DI\ProductionContainer;
use Infocyph\InterMix\DI\Support\AutomaticClassCompiler;
use Infocyph\InterMix\DI\Support\LifetimeEnum;
use Infocyph\InterMix\Exceptions\ContainerException;
use Psr\Container\ContainerInterface;
use Symfony\Component\Process\Process;

final readonly class CompiledExecutionInput
{
    public function __construct(public string $id) {}
}

final readonly class CompiledInputConsumer
{
    public function __construct(public CompiledExecutionInput $input) {}
}

interface CompiledInputMarker {}

final readonly class CompiledCompoundInput implements CompiledInputMarker
{
    public function __construct(public string $id) {}
}

final readonly class CompiledUnionInputConsumer
{
    public function __construct(public CompiledCompoundInput|stdClass $input) {}
}

final readonly class CompiledIntersectionInputConsumer
{
    public function __construct(public CompiledCompoundInput&CompiledInputMarker $input) {}
}

final readonly class CompiledObjectDefaultConsumer
{
    public function __construct(public mixed $value = new stdClass()) {}
}

final readonly class CompiledIndirectCapturingSingleton
{
    public CompiledExecutionInput $input;

    public function __construct(ContainerInterface $container, string $operation)
    {
        $this->input = $operation === 'invoke'
            ? $container->invoke(static fn(CompiledExecutionInput $input) => $input)
            : $container->make(CompiledInputConsumer::class)->input;
    }
}

final readonly class CompiledInputCapturingSingleton
{
    public CompiledExecutionInput $input;

    public function __construct(ContainerInterface $container)
    {
        if (Fiber::getCurrent() instanceof Fiber) {
            Fiber::suspend();
        }
        $this->input = $container->get(CompiledExecutionInput::class);
    }
}

/** @param array{graph: string, digest: string} $report */
function loadInputArtifact(string $loader, string $path, array $report): ProductionContainer
{
    return $loader === 'compiled'
        ? ContainerBuilder::loadProductionArtifact($path, $report['graph'])
        : new StaticRuntimeGenerator()->loadPrevalidated($path, $report['digest']);
}

beforeEach(function () {
    $this->path = sys_get_temp_dir() . '/intermix-native-input-' . bin2hex(random_bytes(8)) . '.php';
    $builder = ContainerBuilder::create(uniqid('native_input_'))
        ->input(CompiledExecutionInput::class)
        ->input('nullable')
        ->alias('execution', CompiledExecutionInput::class)
        ->autowire(CompiledInputConsumer::class, CompiledInputConsumer::class, lifetime: LifetimeEnum::Scoped)
        ->autowire('capturing', CompiledInputCapturingSingleton::class);
    $this->report = $builder->compile($this->path);
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

it('loads declared inputs and their dependants without a builder or release identity', function (string $loader) {
    $runtime = loadInputArtifact($loader, $this->path, $this->report);
    $manifest = json_decode(file_get_contents(dirname($this->report['artifact']) . '/manifest.json'), true, flags: JSON_THROW_ON_ERROR);

    expect($this->report['compiled'])->toContain(CompiledExecutionInput::class, CompiledInputConsumer::class, 'execution', 'nullable')
        ->and($this->report['skipped'])->toBe([])
        ->and($manifest['fallback'])->toBe([
            'required' => false,
            'identity_required' => false,
            'ids' => [],
            'release_identity' => null,
        ]);

    $input = new CompiledExecutionInput('request-1');
    $runtime->withinScope('request', function (ProductionContainer $active) use ($input) {
        expect($active->get(CompiledExecutionInput::class))->toBe($input)
            ->and($active->get('execution'))->toBe($input)
            ->and($active->get(CompiledInputConsumer::class)->input)->toBe($input)
            ->and($active->get('nullable'))->toBeNull();
    }, [CompiledExecutionInput::class => $input, 'nullable' => null]);
})->with(['compiled', 'prevalidated']);

it('preserves declared input selection for compound dependencies', function (string $loader, string $consumer) {
    $builder = ContainerBuilder::create(uniqid('compound_input_'))
        ->input(CompiledCompoundInput::class)
        ->autowire($consumer, $consumer, lifetime: LifetimeEnum::Scoped);
    $report = $builder->compile($this->path, strict: true);
    $runtime = loadInputArtifact($loader, $this->path, $report);
    $input = new CompiledCompoundInput('compound');

    expect($report['skipped'])->toBe([]);
    $runtime->withinScope('request', function ($active) use ($consumer, $input) {
        expect($active->get($consumer)->input)->toBe($input);
    }, [CompiledCompoundInput::class => $input]);
    expect(fn() => $runtime->withinScope('request', fn($active) => $active->get($consumer)))
        ->toThrow(ContainerException::class, 'was not supplied');
})->with(['compiled', 'prevalidated'])->with([CompiledUnionInputConsumer::class, CompiledIntersectionInputConsumer::class]);

it('proves autowire declarations and contextual service references for compound dependencies', function (string $loader, bool $contextual) {
    $builder = ContainerBuilder::create(uniqid('compound_autowire_'))
        ->autowire('declared', CompiledCompoundInput::class, ['id' => 'declared'])
        ->autowire('consumer', CompiledUnionInputConsumer::class);
    if ($contextual) {
        $builder->when(CompiledUnionInputConsumer::class)->needs(CompiledCompoundInput::class)->giveReference('declared');
    } else {
        $builder->alias('input', 'declared');
    }
    $report = $builder->compile($this->path, strict: true);
    $runtime = loadInputArtifact($loader, $this->path, $report);
    expect($runtime->get('consumer')->input)->toBe($runtime->get('declared'));
})->with(['compiled', 'prevalidated'])->with([false, true]);

it('rejects validly shaped manifest mutations through the immutable build identity', function (string $loader, string $field) {
    $path = dirname($this->report['artifact']) . '/manifest.json';
    $manifest = json_decode(file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
    match ($field) {
        'compiled' => $manifest['compiled'] = [],
        'skipped' => $manifest['skipped'] = ['hidden' => 'unsupported'],
        'fallback' => $manifest['fallback']['required'] = true,
        'identity' => $manifest['fallback']['identity_required'] = true,
        'environment' => $manifest['environment'] = 'tampered',
    };
    file_put_contents($path, json_encode($manifest, JSON_THROW_ON_ERROR));
    expect(fn() => loadInputArtifact($loader, $this->path, $this->report))
        ->toThrow(ContainerException::class, 'build identity');
})->with(['compiled', 'prevalidated'])->with(['compiled', 'skipped', 'fallback', 'identity', 'environment']);

it('retains non-exportable constructor defaults in the protected fallback', function () {
    $builder = ContainerBuilder::create(uniqid('object_default_'))
        ->autowire('consumer', CompiledObjectDefaultConsumer::class);
    expect(fn() => $builder->compile($this->path, strict: true))
        ->toThrow(ContainerException::class, 'non-exportable default');
    $report = $builder->compile($this->path);
    expect(fn() => ContainerBuilder::loadProductionArtifact($this->path, $report['graph']))
        ->toThrow(ContainerException::class, 'requires its frozen fallback graph');
    expect($builder->build()->get('consumer')->value)->toBeInstanceOf(stdClass::class)
        ->and($builder->production($this->path)->get('consumer')->value)->toBeInstanceOf(stdClass::class);
    $recipe = new AutomaticClassCompiler()->compile($builder->definitionGraph(), CompiledObjectDefaultConsumer::class);
    expect($recipe['code'])->toBeNull()
        ->and($recipe['reason'])->toContain('non-exportable default');
});

it('blocks singleton capture through nested invocation and fresh construction', function (string $mode, string $operation) {
    $builder = ContainerBuilder::create(uniqid('nested_capture_'))
        ->input(CompiledExecutionInput::class)
        ->alias('execution', CompiledExecutionInput::class)
        ->factory('capturing', static fn($container) => $operation === 'invoke'
            ? $container->invoke(static fn(CompiledExecutionInput $input) => new CompiledInputConsumer($input))
            : ($operation === 'make' ? $container->make(CompiledInputConsumer::class) : $container->get($operation === 'alias' ? 'execution' : CompiledExecutionInput::class)))
        ->releaseIdentity('nested-capture');
    if ($mode === 'production') {
        $builder->compile($this->path);
    }
    $runtime = $mode === 'development' ? $builder->build() : $builder->production($this->path);
    expect(fn() => $runtime->withinScope('request', fn($active) => $active->get('capturing'),
        [CompiledExecutionInput::class => new CompiledExecutionInput('captured')]))
        ->toThrow(ContainerException::class, 'Singleton construction cannot capture');
})->with(['development', 'production'])->with(['get', 'alias', 'invoke', 'make']);

it('rejects alias seeds even after reflection bridges have been activated', function (string $loader) {
    $runtime = loadInputArtifact($loader, $this->path, $this->report);
    expect(fn() => $runtime->withinScope('request', static fn() => null,
        ['execution' => new CompiledExecutionInput('alias')]))
        ->toThrow(ContainerException::class, 'must identify a declared scoped entry or input');
    $runtime->withinScope('request', fn($active) => $active->invoke(static fn(CompiledExecutionInput $input) => $input),
        [CompiledExecutionInput::class => new CompiledExecutionInput('valid')]);
    expect(fn() => $runtime->withinScope('request', static fn() => null,
        ['execution' => new CompiledExecutionInput('alias')]))
        ->toThrow(ContainerException::class, 'must identify a declared scoped entry or input');
})->with(['compiled', 'prevalidated']);

it('blocks compiled singleton capture across lazily created reflection bridges', function (string $loader, string $operation) {
    $builder = ContainerBuilder::create(uniqid('compiled_nested_capture_'))
        ->input(CompiledExecutionInput::class)
        ->autowire('capturing', CompiledIndirectCapturingSingleton::class, ['operation' => $operation]);
    $report = $builder->compile($this->path, strict: true);
    $runtime = loadInputArtifact($loader, $this->path, $report);
    expect(fn() => $runtime->withinScope('request', fn($active) => $active->get('capturing'),
        [CompiledExecutionInput::class => new CompiledExecutionInput('captured')]))
        ->toThrow(ContainerException::class, 'Singleton construction cannot capture');
})->with(['compiled', 'prevalidated'])->with(['invoke', 'make']);

it('resolves fresh invocations through compiled input bridges within each execution', function (string $loader) {
    $runtime = loadInputArtifact($loader, $this->path, $this->report);
    foreach (['one', 'two'] as $id) {
        $input = new CompiledExecutionInput($id);
        $runtime->withinScope('request', function ($active) use ($input) {
            expect($active->invoke(static fn(CompiledExecutionInput $input) => $input))->toBe($input)
                ->and($active->make(CompiledInputConsumer::class)->input)->toBe($input);
        }, [CompiledExecutionInput::class => $input]);
    }
    expect(fn() => $runtime->withinScope('request', fn($active) => $active->invoke(static fn(CompiledExecutionInput $input) => $input)))
        ->toThrow(ContainerException::class, 'was not supplied');
})->with(['compiled', 'prevalidated']);

it('synchronizes lazily activated reflection scopes on concurrent existing carriers', function (string $loader) {
    $runtime = loadInputArtifact($loader, $this->path, $this->report);
    $carriers = [];
    foreach (['one', 'two'] as $id) {
        $carriers[] = new Fiber(static fn() => $runtime->withinScope('request', static function ($active) use ($id) {
            Fiber::suspend();
            if ($id === 'two') {
                $context = $active->captureScopeContext();
                expect($context)->toBeInstanceOf(\Infocyph\InterMix\DI\ScopeContext::class);
            }

            return $active->invoke(static fn(CompiledExecutionInput $input) => $input->id);
        }, [CompiledExecutionInput::class => new CompiledExecutionInput($id)]));
    }
    foreach ($carriers as $carrier) {
        $carrier->start();
    }
    foreach ($carriers as $index => $carrier) {
        $carrier->resume();
        expect($carrier->getReturn())->toBe($index === 0 ? 'one' : 'two');
    }
})->with(['compiled', 'prevalidated']);

it('preserves numeric string identifiers in native and protected fallback manifests', function () {
    $builder = ContainerBuilder::create(uniqid('numeric_input_'))
        ->input('123')
        ->factory('456', static fn() => new stdClass())
        ->releaseIdentity('numeric-input');
    $report = $builder->compile($this->path);
    expect($report['compiled'])->toContain('123');
    $runtime = $builder->productionPrevalidated($this->path, $report['digest']);
    $runtime->withinScope('request', function ($active) {
        expect($active->get('123'))->toBe('seed')
            ->and($active->get('456'))->toBeInstanceOf(stdClass::class);
    }, ['123' => 'seed']);
});

it('keeps unknown factory result types behind the compound fallback boundary', function () {
    $builder = ContainerBuilder::create(uniqid('compound_factory_'))
        ->factory('input', static fn() => new CompiledCompoundInput('factory'))
        ->autowire('consumer', CompiledUnionInputConsumer::class)
        ->releaseIdentity('compound-factory');
    $report = $builder->compile($this->path);
    expect($report['skipped']['consumer'])->toContain('unknown runtime type')
        ->and($builder->production($this->path)->get('consumer')->input->id)->toBe('factory');
    expect(fn() => ContainerBuilder::loadProductionArtifact($this->path, $report['graph']))
        ->toThrow(ContainerException::class, 'requires its frozen fallback graph');
});

it('fails closed for absent inputs and undeclared or singleton scope seeds', function (string $loader) {
    $runtime = loadInputArtifact($loader, $this->path, $this->report);
    expect(fn() => $runtime->get(CompiledExecutionInput::class))
        ->toThrow(ContainerException::class, 'requires an active scope');
    expect(fn() => $runtime->withinScope('request', fn($active) => $active->get(CompiledExecutionInput::class)))
        ->toThrow(ContainerException::class, 'was not supplied');

    foreach (['undeclared', 'capturing'] as $id) {
        $called = false;
        expect(fn() => $runtime->withinScope('request', function () use (&$called) {
            $called = true;
        }, [$id => new stdClass()]))->toThrow(ContainerException::class, 'must identify a declared scoped entry or input');
        expect($called)->toBeFalse();
    }
})->with(['compiled', 'prevalidated']);

it('isolates sequential requests jobs and scheduler executions including exceptional cleanup', function (string $loader) {
    $runtime = loadInputArtifact($loader, $this->path, $this->report);
    $references = [];
    foreach (['request', 'job', 'scheduler'] as $scope) {
        $input = new CompiledExecutionInput($scope);
        $references[] = WeakReference::create($input);
        expect(fn() => $runtime->withinScope($scope, function ($active) use ($input) {
            expect($active->get(CompiledInputConsumer::class)->input)->toBe($input);
            throw new RuntimeException('execution failed');
        }, [CompiledExecutionInput::class => $input]))->toThrow(RuntimeException::class, 'execution failed');
        unset($input);
        expect(fn() => $runtime->get(CompiledExecutionInput::class))->toThrow(ContainerException::class, 'active scope');
        expect(fn() => $runtime->withinScope($scope, fn($active) => $active->get(CompiledExecutionInput::class)))
            ->toThrow(ContainerException::class, 'was not supplied');
    }
    foreach ($references as $reference) {
        expect($reference->get())->toBeNull();
    }
})->with(['compiled', 'prevalidated']);

it('keeps concurrent scopes isolated and restores their nested input frames', function (string $loader) {
    $runtime = loadInputArtifact($loader, $this->path, $this->report);
    $run = static fn(string $id) => new Fiber(static fn() => $runtime->withinScope(
        'request',
        static function ($active) use ($id) {
            Fiber::suspend($active->get(CompiledExecutionInput::class)->id);
            $active->withinScope('nested', static function ($nested) {
                expect(fn() => $nested->get(CompiledExecutionInput::class))
                    ->toThrow(ContainerException::class, 'was not supplied');
            });
            $nestedId = $active->withinScope('nested', static fn($nested) => $nested->get(CompiledExecutionInput::class)->id,
                [CompiledExecutionInput::class => new CompiledExecutionInput($id . '-nested')]);
            expect($nestedId)->toBe($id . '-nested');

            return $active->get(CompiledExecutionInput::class)->id;
        },
        [CompiledExecutionInput::class => new CompiledExecutionInput($id)],
    ));
    $first = $run('first');
    $second = $run('second');
    expect($first->start())->toBe('first')->and($second->start())->toBe('second');
    expect(fn() => $runtime->get(CompiledExecutionInput::class))->toThrow(ContainerException::class, 'active scope');
    $second->resume();
    $first->resume();
    expect($first->getReturn())->toBe('first')->and($second->getReturn())->toBe('second');
})->with(['compiled', 'prevalidated']);

it('shares declared input seeds only through an explicitly attached scope context', function (string $loader) {
    $runtime = loadInputArtifact($loader, $this->path, $this->report);
    $input = new CompiledExecutionInput('owner');
    $context = $runtime->withinScope('request', static function ($owner) use ($input) {
        $context = $owner->captureScopeContext();
        $child = new Fiber(static fn() => $owner->withinScopeContext($context, static function ($active) use ($input) {
            expect($active->get(CompiledExecutionInput::class))->toBe($input);
        }));
        $child->start();
        expect($owner->get(CompiledExecutionInput::class))->toBe($input);

        return $context;
    }, [CompiledExecutionInput::class => $input]);
    expect(fn() => $runtime->withinScopeContext($context, static fn($active) => $active->get(CompiledExecutionInput::class)))
        ->toThrow(ContainerException::class, 'no longer active');
})->with(['compiled', 'prevalidated']);

it('rejects singleton capture of a seeded compiled input during runtime construction', function (string $loader) {
    $runtime = loadInputArtifact($loader, $this->path, $this->report);
    $input = new CompiledExecutionInput('request');
    $runtime->withinScope('request', function ($active) use ($input) {
        expect($active->get(CompiledExecutionInput::class))->toBe($input);
        expect(fn() => $active->get('capturing'))->toThrow(ContainerException::class, 'Singleton construction cannot capture scoped entry');
        expect($active->get(CompiledExecutionInput::class))->toBe($input);
    }, [CompiledExecutionInput::class => $input]);
})->with(['compiled', 'prevalidated']);

it('rejects declared singleton dependencies on inputs during graph finalization', function () {
    $builder = ContainerBuilder::create(uniqid('captive_input_'))
        ->input(CompiledExecutionInput::class)
        ->autowire('singleton', CompiledInputConsumer::class);
    expect(fn() => $builder->compile($this->path, strict: true))
        ->toThrow(ContainerException::class, 'depends on scoped entry');
});

it('keeps singleton input capture guards local to the constructing execution carrier', function (string $loader) {
    $runtime = loadInputArtifact($loader, $this->path, $this->report);
    $first = new Fiber(static fn() => $runtime->withinScope('request', static function ($active) {
        expect(fn() => $active->get('capturing'))
            ->toThrow(ContainerException::class, 'Singleton construction cannot capture scoped entry');

        return $active->get(CompiledExecutionInput::class)->id;
    }, [CompiledExecutionInput::class => new CompiledExecutionInput('first')]));
    $first->start();
    expect($first->isSuspended())->toBeTrue();

    $second = new Fiber(static fn() => $runtime->withinScope('request',
        static fn($active) => $active->get(CompiledExecutionInput::class)->id,
        [CompiledExecutionInput::class => new CompiledExecutionInput('second')]));
    $second->start();
    expect($second->getReturn())->toBe('second');
    $first->resume();
    expect($first->getReturn())->toBe('first');
})->with(['compiled', 'prevalidated']);

it('compiles input definitions in strict mode', function () {
    $builder = ContainerBuilder::create(uniqid('strict_input_'))->input(CompiledExecutionInput::class);
    $report = $builder->compile($this->path, strict: true);
    expect($report['compiled'])->toContain(CompiledExecutionInput::class)->and($report['skipped'])->toBe([]);
});

it('loads and seeds an input artifact in a fresh process with no source graph', function (string $loader) {
    $code = <<<'PHP'
        require $argv[1];
        $generator = new \Infocyph\InterMix\DI\Build\StaticRuntimeGenerator();
        $runtime = $argv[4] === 'compiled'
            ? $generator->loadCompiled($argv[2], $argv[3], null)
            : $generator->loadPrevalidated($argv[2], $argv[3]);
        if (class_exists(\Infocyph\InterMix\DI\ContainerBuilder::class, false)
            || class_exists(\Infocyph\InterMix\DI\Build\DefinitionGraph::class, false)) {
            throw new \RuntimeException('Source graph was loaded.');
        }
        $value = $runtime->withinScope('job', static fn($active) => $active->get('nullable'), ['nullable' => 'fresh']);
        fwrite(STDOUT, $value);
        PHP;
    $process = new Process([
        PHP_BINARY, '-r', $code, realpath(__DIR__ . '/../../vendor/autoload.php'), $this->path,
        $this->report[$loader === 'compiled' ? 'graph' : 'digest'], $loader,
    ]);
    $process->run();
    expect($process->isSuccessful())->toBeTrue($process->getErrorOutput())
        ->and($process->getOutput())->toBe('fresh');
})->with(['compiled', 'prevalidated']);

it('rejects manifest tampering and incompatible artifact versions with native inputs', function (string $loader, string $field, mixed $value) {
    $manifestPath = dirname($this->report['artifact']) . '/manifest.json';
    $manifest = json_decode(file_get_contents($manifestPath), true, flags: JSON_THROW_ON_ERROR);
    $manifest[$field] = $value;
    file_put_contents($manifestPath, json_encode($manifest, JSON_THROW_ON_ERROR));
    expect(fn() => loadInputArtifact($loader, $this->path, $this->report))->toThrow(ContainerException::class);
})->with(['compiled', 'prevalidated'])->with([
    'old ABI' => ['abi', 1],
    'wrong major' => ['intermix_major', 10],
    'wrong PHP' => ['php', '8.3'],
    'changed digest' => ['digest', str_repeat('0', 32)],
    'invalid fallback' => ['fallback', ['required' => false]],
    'changed build' => ['build', str_repeat('0', 32)],
]);

it('rejects wrong deployment graph environment and digest identities for native input artifacts', function () {
    $generator = new StaticRuntimeGenerator();
    expect(fn() => $generator->loadCompiled($this->path, str_repeat('0', 32), null))
        ->toThrow(ContainerException::class, 'graph identity');
    expect(fn() => $generator->loadCompiled($this->path, $this->report['graph'], 'wrong'))
        ->toThrow(ContainerException::class, 'environment');
    expect(fn() => $generator->loadPrevalidated($this->path, str_repeat('0', 32)))
        ->toThrow(ContainerException::class, 'deployment digest');
    $different = ContainerBuilder::create(uniqid('different_input_'))->input('other');
    expect(fn() => $different->production($this->path))->toThrow(ContainerException::class, 'graph identity');
});

it('rejects modified generated PHP before loading a native input artifact', function () {
    file_put_contents($this->report['artifact'], "\n// modified artifact\n", FILE_APPEND);
    expect(fn() => ContainerBuilder::loadProductionArtifact($this->path, $this->report['graph']))
        ->toThrow(ContainerException::class, 'hash does not match');
});

it('preserves fallback and matching release identity requirements for dynamic factories and hooks', function (string $feature) {
    $builder = ContainerBuilder::create(uniqid('hybrid_input_'))->input(CompiledExecutionInput::class);
    match ($feature) {
        'factory' => $builder->factory('dynamic', static fn() => new stdClass()),
        'resolving' => $builder->onResolving(CompiledExecutionInput::class, static fn() => null),
        'resolved' => $builder->onResolved(CompiledExecutionInput::class, static fn() => null),
        'scope-leave' => $builder->onScopeLeave('request', static fn() => null),
    };
    $report = $builder->compile($this->path);
    expect(fn() => ContainerBuilder::loadProductionArtifact($this->path, $report['graph']))
        ->toThrow(ContainerException::class, 'requires its frozen fallback graph');
    expect(fn() => new StaticRuntimeGenerator()->loadPrevalidated($this->path, $report['digest']))
        ->toThrow(ContainerException::class, 'requires its frozen fallback graph');
    expect(fn() => $builder->production($this->path))
        ->toThrow(ContainerException::class, 'explicit matching release identity');
})->with(['factory', 'resolving', 'resolved', 'scope-leave']);

it('accepts matching frozen hybrid fallback and rejects a wrong release identity with native inputs', function () {
    $builder = ContainerBuilder::create(uniqid('trusted_input_fallback_'))
        ->releaseIdentity('release-a')
        ->input(CompiledExecutionInput::class)
        ->factory('dynamic', static fn() => new stdClass());
    $report = $builder->compile($this->path);
    $runtime = $builder->productionPrevalidated($this->path, $report['digest']);
    $input = new CompiledExecutionInput('hybrid');
    $runtime->withinScope('request', function ($active) use ($input) {
        expect($active->get(CompiledExecutionInput::class))->toBe($input)
            ->and($active->get('dynamic'))->toBeInstanceOf(stdClass::class);
    }, [CompiledExecutionInput::class => $input]);

    $wrong = ContainerBuilder::create(uniqid('wrong_input_fallback_'))
        ->releaseIdentity('release-b')
        ->input(CompiledExecutionInput::class)
        ->factory('dynamic', static fn() => new stdClass());
    expect(fn() => $wrong->production($this->path))
        ->toThrow(ContainerException::class, 'release identity does not match');
});
