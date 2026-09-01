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
     *   skipped: array<string, string>,
     *   digest: string
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
        $digest = hash('xxh128', $source);
        AtomicFileWriter::write(
            $filePath,
            $source,
            function (string $temporaryPath): void {
                $this->loadRuntime($temporaryPath);
            },
        );
        $this->writeManifest(
            $filePath,
            $digest,
            array_keys($plans),
            $planned['skipped'],
            $graph->environment(),
        );

        return [
            'runtime' => $this->loadPrevalidated($filePath, $digest, $fallback),
            'compiled' => array_keys($plans),
            'skipped' => $planned['skipped'],
            'digest' => $digest,
        ];
    }

    public function load(string $filePath, ?Container $fallback = null): ProductionContainer
    {
        $manifest = $this->validateManifest($filePath);
        $this->assertEnvironmentMatches($manifest, $fallback);

        return $this->attachFallback($this->loadRuntime($filePath), $fallback);
    }

    /**
     * Load an artifact whose xxh128 digest was validated during deployment.
     *
     * This deliberately does not hash the runtime file. The caller must source
     * the digest from trusted immutable deployment metadata.
     */
    public function loadPrevalidated(
        string $filePath,
        string $expectedDigest,
        ?Container $fallback = null,
    ): ProductionContainer {
        $this->assertDigest($expectedDigest);
        $manifest = $this->readManifest($filePath);
        $this->assertManifestAbi($manifest);
        if (!hash_equals($manifest['digest'], $expectedDigest)) {
            throw new ContainerException(
                'Prevalidated static runtime does not match the active deployment digest.',
            );
        }
        $this->assertEnvironmentMatches($manifest, $fallback);

        return $this->attachFallback($this->loadRuntime($filePath), $fallback);
    }

    /** @param array{abi: int, digest: string, environment: ?string} $manifest */
    private function assertEnvironmentMatches(array $manifest, ?Container $fallback): void
    {
        if (!$fallback instanceof Container) {
            return;
        }

        $environment = $fallback->getRepository()->getEnvironment();
        if ($manifest['environment'] !== $environment) {
            throw new ContainerException(
                'Static runtime environment does not match the configured container environment.',
            );
        }
    }

    /** @param array{abi: int, digest: string, environment: ?string} $manifest */
    private function assertManifestAbi(array $manifest): void
    {
        if ($manifest['abi'] !== self::ARTIFACT_ABI) {
            throw new ContainerException(
                "Unsupported static runtime ABI '{$manifest['abi']}'.",
            );
        }
    }

    private function assertDigest(string $digest): void
    {
        if (preg_match('/^[a-f0-9]{32}$/D', $digest) !== 1) {
            throw new ContainerException(
                'Prevalidated static runtime digest must be a lowercase xxh128 hexadecimal value.',
            );
        }
    }

    private function attachFallback(ProductionContainer $runtime, ?Container $fallback): ProductionContainer
    {
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

    /** @return array{abi: int, digest: string, environment: ?string} */
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
            || !isset($manifest['abi'], $manifest['digest'])
            || !array_key_exists('environment', $manifest)
            || !is_int($manifest['abi'])
            || !is_string($manifest['digest'])
            || preg_match('/^[a-f0-9]{32}$/D', $manifest['digest']) !== 1
            || (!is_string($manifest['environment']) && $manifest['environment'] !== null)
        ) {
            throw new ContainerException('Static runtime manifest has an invalid shape.');
        }

        return [
            'abi' => $manifest['abi'],
            'digest' => $manifest['digest'],
            'environment' => $manifest['environment'],
        ];
    }

    /** @return array{abi: int, digest: string, environment: ?string} */
    private function validateManifest(string $filePath): array
    {
        $manifest = $this->readManifest($filePath);
        $this->assertManifestAbi($manifest);

        $hash = hash_file('xxh128', $filePath);
        if (!is_string($hash) || !hash_equals($manifest['digest'], $hash)) {
            throw new ContainerException('Static runtime artifact hash does not match its manifest.');
        }

        return $manifest;
    }

    /**
     * @param list<string> $compiled
     * @param array<string, string> $skipped
     */
    private function writeManifest(
        string $filePath,
        string $digest,
        array $compiled,
        array $skipped,
        ?string $environment,
    ): void {
        try {
            $manifest = json_encode(
                [
                    'abi' => self::ARTIFACT_ABI,
                    'digest' => $digest,
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
