<?php

declare(strict_types=1);

use Infocyph\InterMix\DI\ContainerBuilder;
use Infocyph\InterMix\DI\RuntimeContainerInterface;
use Infocyph\InterMix\DI\Support\LifetimeEnum;
use Infocyph\InterMix\DI\Support\ServiceProviderInterface;

final class Migration11Clock {}

final class Migration11Request
{
    public function __construct(public string $id) {}
}

final class Migration11Service
{
    public function __construct(
        public Migration11Clock $clock,
        public Migration11Request $request,
    ) {}
}

final class Migration11Provider implements ServiceProviderInterface
{
    public function register(ContainerBuilder $builder): void
    {
        $builder->value('migration.provider', 'ready');
    }
}

it('executes the documented builder runtime and scope migration', function (): void {
    $builder = ContainerBuilder::create(uniqid('migration-11-', true))
        ->import(new Migration11Provider())
        ->autowire(Migration11Clock::class, Migration11Clock::class)
        ->input(Migration11Request::class)
        ->autowire(
            Migration11Service::class,
            Migration11Service::class,
            lifetime: LifetimeEnum::Scoped,
            tags: ['migration.request'],
        );

    $runtime = $builder->build();

    expect($runtime)->toBeInstanceOf(RuntimeContainerInterface::class)
        ->and($runtime->get('migration.provider'))->toBe('ready');

    $request = new Migration11Request('request-1');
    $result = $runtime->withinScope(
        'request-1',
        static function (RuntimeContainerInterface $active) use ($request): array {
            $service = $active->get(Migration11Service::class);
            $again = $active->get(Migration11Service::class);
            $tagged = iterator_to_array($active->tagged('migration.request'));

            return [
                $service,
                $again,
                $tagged[Migration11Service::class],
                $active->invoke(
                    static fn (Migration11Service $resolved): string => $resolved->request->id,
                ),
            ];
        },
        [Migration11Request::class => $request],
    );

    expect($result[0])->toBe($result[1])
        ->and($result[1])->toBe($result[2])
        ->and($result[3])->toBe('request-1');
});

it('keeps removed mutable runtime surfaces non-public', function (): void {
    $builderMethods = get_class_methods(ContainerBuilder::class);
    foreach ([
        'bind',
        'bindFactory',
        'definitions',
        'development',
        'onMissing',
        'options',
        'registration',
        'scoped',
        'singleton',
        'transient',
    ] as $method) {
        expect($builderMethods)->not->toContain($method);
    }

    $runtime = ContainerBuilder::create(uniqid('migration-runtime-', true))->build();
    $runtimeMethods = get_class_methods($runtime);

    foreach ([
        'call',
        'definitions',
        'enterScope',
        'getReturn',
        'invocation',
        'leaveScope',
        'options',
        'registration',
        'useCompiled',
        'usePrevalidated',
    ] as $method) {
        expect($runtimeMethods)->not->toContain($method);
    }
});

it('keeps current documentation free of removed 10.x runtime examples', function (): void {
    $root = dirname(__DIR__, 2);
    $paths = [$root . '/README.md'];
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($root . '/docs', FilesystemIterator::SKIP_DOTS),
    );

    foreach ($iterator as $file) {
        if (!$file instanceof SplFileInfo || !$file->isFile()) {
            continue;
        }

        $path = $file->getPathname();
        if (!str_ends_with($path, '.rst') && !str_ends_with($path, '.md')) {
            continue;
        }
        if (str_contains($path, DIRECTORY_SEPARATOR . 'plans' . DIRECTORY_SEPARATOR)
            || str_contains($path, 'intermix-10')
            || str_ends_with($path, 'upgrade-11.0.rst')
        ) {
            continue;
        }

        $paths[] = $path;
    }

    $forbidden = [
        '->definitions(',
        '->registration(',
        '->options(',
        '->invocation(',
        '->getReturn(',
        '->call(',
        '->enterScope(',
        '->leaveScope(',
        '->compileTo(',
        '->useCompiled(',
        '->usePrevalidated(',
        '->development(',
        '->findByTag(',
        '->bindFactory(',
        'ManagerProxy',
        'registerClass(',
        'registerMethod(',
        'registerProperty(',
        'addDefinitions(',
    ];

    foreach ($paths as $path) {
        $content = file_get_contents($path);
        expect($content)->toBeString();

        foreach ($forbidden as $needle) {
            expect($content)->not->toContain($needle, "{$path} advertises removed API {$needle}");
        }
    }
});
