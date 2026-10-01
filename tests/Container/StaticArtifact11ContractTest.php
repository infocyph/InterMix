<?php

declare(strict_types=1);

use Infocyph\InterMix\DI\Build\StaticRuntimeGenerator;
use Infocyph\InterMix\DI\ContainerBuilder;
use Infocyph\InterMix\Exceptions\ContainerException;

final class StaticArtifact11Service {}

function staticArtifact11Path(): string
{
    return sys_get_temp_dir() . '/intermix-static-artifact11-' . bin2hex(random_bytes(8)) . '.php';
}

function removeStaticArtifact11Tree(string $path): void
{
    foreach ([$path, $path . '.meta.json'] as $link) {
        if (is_link($link) || is_file($link)) {
            unlink($link);
        }
    }

    $root = $path . '.builds';
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
}

it('publishes an immutable versioned build and atomically activates its runtime pointer', function () {
    $builder = ContainerBuilder::create(uniqid('artifact11_versioned_'))
        ->autowire('service', StaticArtifact11Service::class);
    $path = staticArtifact11Path();

    try {
        $report = $builder->compile($path);
        $runtime = $builder->productionPrevalidated($path, $report['digest']);

        expect(is_link($path))->toBeTrue()
            ->and(realpath($path))->toBe($report['artifact'])
            ->and(basename(dirname($report['artifact'])))->toBe($report['build'])
            ->and(is_file(dirname($report['artifact']) . '/manifest.json'))->toBeTrue()
            ->and($report['graph'])->toMatch('/^[a-f0-9]{32}$/')
            ->and($runtime->get('service'))->toBeInstanceOf(StaticArtifact11Service::class);
    } finally {
        removeStaticArtifact11Tree($path);
    }
});

it('rejects a mismatched frozen graph before activating the artifact runtime', function () {
    $compiler = ContainerBuilder::create(uniqid('artifact11_graph_source_'))
        ->value('mode', 'source');
    $path = staticArtifact11Path();

    try {
        $compiler->compile($path);
        $loader = ContainerBuilder::create(uniqid('artifact11_graph_loader_'))
            ->value('mode', 'different');

        expect(fn() => $loader->production($path))
            ->toThrow(ContainerException::class, 'graph identity');
    } finally {
        removeStaticArtifact11Tree($path);
    }
});

it('requires an explicit matching release identity for opaque hybrid fallback state', function () {
    $path = staticArtifact11Path();

    try {
        $builder = ContainerBuilder::create(uniqid('artifact11_hybrid_'))
            ->releaseIdentity('release-a')
            ->factory('dynamic', static fn(): object => new stdClass());
        $report = $builder->compile($path);
        $runtime = $builder->productionPrevalidated($path, $report['digest']);

        expect($report['skipped'])->toHaveKey('dynamic')
            ->and($runtime->get('dynamic'))->toBeInstanceOf(stdClass::class);

        $missing = ContainerBuilder::create(uniqid('artifact11_hybrid_missing_'))
            ->factory('dynamic', static fn(): object => new stdClass());
        expect(fn() => $missing->production($path))
            ->toThrow(ContainerException::class, 'release identity');

        $wrong = ContainerBuilder::create(uniqid('artifact11_hybrid_wrong_'))
            ->releaseIdentity('release-b')
            ->factory('dynamic', static fn(): object => new stdClass());
        expect(fn() => $wrong->production($path))
            ->toThrow(ContainerException::class, 'release identity');
    } finally {
        removeStaticArtifact11Tree($path);
    }
});

it('rejects an old ABI manifest before requiring its runtime file', function () {
    $path = staticArtifact11Path();
    $build = str_repeat('a', 32);
    $directory = $path . '.builds/' . $build;
    $sentinel = $path . '.executed';

    try {
        mkdir($directory, 0755, true);
        $runtimePath = $directory . '/runtime.php';
        file_put_contents(
            $runtimePath,
            "<?php file_put_contents(" . var_export($sentinel, true) . ", 'yes'); return new stdClass();\n",
        );
        $digest = hash_file('xxh128', $runtimePath);
        file_put_contents(
            $directory . '/manifest.json',
            json_encode([
                'abi' => 1,
                'intermix_major' => 10,
                'php' => PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION,
                'digest' => $digest,
                'graph' => str_repeat('0', 32),
                'environment' => null,
                'compiled' => [],
                'skipped' => [],
                'fallback' => [
                    'required' => false,
                    'ids' => [],
                    'release_identity' => null,
                ],
                'artifact' => 'runtime.php',
                'build' => $build,
            ], JSON_THROW_ON_ERROR),
        );
        symlink($runtimePath, $path);

        expect(fn() => new StaticRuntimeGenerator()->load($path))
            ->toThrow(ContainerException::class, 'ABI')
            ->and(is_file($sentinel))->toBeFalse();
    } finally {
        if (is_file($sentinel)) {
            unlink($sentinel);
        }
        removeStaticArtifact11Tree($path);
    }
});

it('preserves the active build when publication of a replacement build fails', function () {
    $path = staticArtifact11Path();
    $probe = staticArtifact11Path();

    try {
        $first = ContainerBuilder::create(uniqid('artifact11_first_'))->value('mode', 'one');
        $first->compile($path);
        $active = realpath($path);

        $second = ContainerBuilder::create(uniqid('artifact11_second_'))->value('mode', 'two');
        $next = $second->compile($probe);
        mkdir($path . '.builds/' . $next['build'], 0755, true);

        $replacement = ContainerBuilder::create(uniqid('artifact11_replacement_'))->value('mode', 'two');
        expect(fn() => $replacement->compile($path))
            ->toThrow(ContainerException::class, 'incomplete')
            ->and(realpath($path))->toBe($active)
            ->and($first->production($path)->get('mode'))->toBe('one');
    } finally {
        removeStaticArtifact11Tree($path);
        removeStaticArtifact11Tree($probe);
    }
});
