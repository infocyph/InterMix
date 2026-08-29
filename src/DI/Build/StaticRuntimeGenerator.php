<?php

declare(strict_types=1);

namespace Infocyph\InterMix\DI\Build;

use Infocyph\InterMix\DI\Container;
use Infocyph\InterMix\DI\ProductionContainer;
use Infocyph\InterMix\Exceptions\ContainerException;
use Infocyph\InterMix\Internal\AtomicFileWriter;
use JsonException;

/** @internal */
final class StaticRuntimeGenerator
{
    private const int ARTIFACT_ABI = 1;

    private const string MANIFEST_SUFFIX = '.meta.json';

    /**
     * @return array{
     *   runtime: ProductionContainer,
     *   compiled: list<string>,
     *   skipped: array<string, string>
     * }
     */
    public function generate(
        DefinitionGraph $graph,
        string $filePath,
        ?Container $fallback = null,
    ): array {
        $planned = new StaticRuntimePlanner()->plan($graph);
        $plans = $planned['plans'];
        $slots = [];
        foreach (array_keys($plans) as $slot => $id) {
            $slots[$id] = $slot;
        }

        $source = new StaticRuntimeRenderer()->render($graph, $plans, $slots);
        AtomicFileWriter::write(
            $filePath,
            $source,
            function (string $temporaryPath): void {
                $this->loadRuntime($temporaryPath);
            },
        );
        $this->writeManifest(
            $filePath,
            $source,
            array_keys($plans),
            $planned['skipped'],
            $graph->environment(),
        );

        return [
            'runtime' => $this->load($filePath, $fallback),
            'compiled' => array_keys($plans),
            'skipped' => $planned['skipped'],
        ];
    }

    public function load(string $filePath, ?Container $fallback = null): ProductionContainer
    {
        $this->validateManifest($filePath);
        $runtime = $this->loadRuntime($filePath);
        if ($fallback instanceof Container) {
            $runtime->attachFallback($fallback);
        }

        return $runtime;
    }

    private function loadRuntime(string $filePath): ProductionContainer
    {
        if (!is_file($filePath) || !is_readable($filePath)) {
            throw new ContainerException("Static runtime artifact is not readable: '$filePath'.");
        }

        $runtime = require $filePath;
        if (!$runtime instanceof ProductionContainer) {
            throw new ContainerException('Static runtime artifact must return a production container.');
        }

        return $runtime;
    }

    private function manifestPath(string $filePath): string
    {
        return $filePath . self::MANIFEST_SUFFIX;
    }

    /** @return array{abi: int, sha256: string} */
    private function readManifest(string $filePath): array
    {
        $manifestPath = $this->manifestPath($filePath);
        if (!is_file($manifestPath) || !is_readable($manifestPath)) {
            throw new ContainerException("Static runtime manifest is not readable: '$manifestPath'.");
        }

        $contents = file_get_contents($manifestPath);
        if (!is_string($contents)) {
            throw new ContainerException("Unable to read static runtime manifest: '$manifestPath'.");
        }

        try {
            $manifest = json_decode($contents, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new ContainerException('Static runtime manifest is invalid JSON.', previous: $exception);
        }
        if (!is_array($manifest)
            || !isset($manifest['abi'], $manifest['sha256'])
            || !is_int($manifest['abi'])
            || !is_string($manifest['sha256'])
        ) {
            throw new ContainerException('Static runtime manifest has an invalid shape.');
        }

        return ['abi' => $manifest['abi'], 'sha256' => $manifest['sha256']];
    }

    private function validateManifest(string $filePath): void
    {
        $manifest = $this->readManifest($filePath);
        if ($manifest['abi'] !== self::ARTIFACT_ABI) {
            throw new ContainerException(
                "Unsupported static runtime ABI '{$manifest['abi']}'.",
            );
        }

        $hash = hash_file('sha256', $filePath);
        if (!is_string($hash) || !hash_equals($manifest['sha256'], $hash)) {
            throw new ContainerException('Static runtime artifact hash does not match its manifest.');
        }
    }

    /**
     * @param list<string> $compiled
     * @param array<string, string> $skipped
     */
    private function writeManifest(
        string $filePath,
        string $source,
        array $compiled,
        array $skipped,
        ?string $environment,
    ): void {
        try {
            $manifest = json_encode(
                [
                    'abi' => self::ARTIFACT_ABI,
                    'sha256' => hash('sha256', $source),
                    'environment' => $environment,
                    'compiled' => $compiled,
                    'skipped' => $skipped,
                ],
                JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
            ) . "\n";
        } catch (JsonException $exception) {
            throw new ContainerException('Unable to encode static runtime manifest.', previous: $exception);
        }

        AtomicFileWriter::write(
            $this->manifestPath($filePath),
            $manifest,
            static function (string $temporaryPath): void {
                $contents = file_get_contents($temporaryPath);
                if (!is_string($contents)) {
                    throw new ContainerException('Unable to validate static runtime manifest.');
                }

                try {
                    $decoded = json_decode($contents, true, flags: JSON_THROW_ON_ERROR);
                } catch (JsonException $exception) {
                    throw new ContainerException('Static runtime manifest is invalid JSON.', previous: $exception);
                }
                if (!is_array($decoded)) {
                    throw new ContainerException('Static runtime manifest must decode to an object.');
                }
            },
        );
    }
}
