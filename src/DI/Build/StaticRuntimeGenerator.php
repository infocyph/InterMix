<?php

declare(strict_types=1);

namespace Infocyph\InterMix\DI\Build;

use Infocyph\InterMix\DI\Internal\ConfigurationContainer;
use Infocyph\InterMix\DI\Internal\ProductionContainerAccess;
use Infocyph\InterMix\DI\ProductionContainer;
use Infocyph\InterMix\Exceptions\ContainerException;
use Infocyph\InterMix\Internal\AtomicFileWriter;
use JsonException;

/** @internal */
final class StaticRuntimeGenerator
{
    /**
     * @return array{
     *   runtime: ProductionContainer,
     *   compiled: list<string>,
     *   skipped: array<string, string>,
     *   digest: string,
     *   graph: string,
     *   build: string,
     *   artifact: string
     * }
     */
    public function generate(
        DefinitionGraph $graph,
        string $filePath,
        ?ConfigurationContainer $fallback = null,
        ?string $releaseIdentity = null,
    ): array {
        $planned = new StaticRuntimePlanner()->plan($graph);
        $plans = $planned['plans'];
        $slots = [];
        foreach (array_keys($plans) as $slot => $rawId) {
            $slots[(string) $rawId] = $slot;
        }
        $compiled = array_map(
            static fn(int|string $id): string => (string) $id,
            array_keys($plans),
        );

        $source = new StaticRuntimeRenderer()->render($graph, $plans, $slots);
        $source = new StaticScopedConstructionGuard()->apply($source, $plans, $slots);
        $digest = hash('xxh128', $source);
        $metadata = new StaticRuntimeArtifactMetadata();
        $graphIdentity = $metadata->graphIdentity($graph, $plans, $planned['skipped']);
        $manifest = $metadata->withBuildIdentity([
            'abi' => StaticRuntimeArtifactMetadata::ABI,
            'intermix_major' => StaticRuntimeArtifactMetadata::INTERMIX_MAJOR,
            'php' => PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION,
            'digest' => $digest,
            'graph' => $graphIdentity,
            'environment' => $graph->environment(),
            'compiled' => $compiled,
            'skipped' => $planned['skipped'],
            'fallback' => $metadata->fallbackMetadata(
                $graph,
                $plans,
                $planned['skipped'],
                $releaseIdentity,
            ),
            'artifact' => StaticRuntimeArtifactMetadata::RUNTIME_NAME,
        ]);
        $build = $manifest['build'];
        $manifestJson = $metadata->encode($manifest);

        $buildDirectory = $this->stageBuild($filePath, $source, $manifestJson, $build);
        $this->activateBuild($filePath, $buildDirectory);

        $runtime = $fallback instanceof ConfigurationContainer
            ? $this->loadPrevalidated(
                $filePath,
                $digest,
                $fallback,
                $graph,
                $releaseIdentity,
            )
            : $this->loadRuntime($this->artifactPath($filePath));

        return [
            'runtime' => $runtime,
            'compiled' => $compiled,
            'skipped' => $planned['skipped'],
            'digest' => $digest,
            'graph' => $graphIdentity,
            'build' => $build,
            'artifact' => $this->artifactPath($filePath),
        ];
    }

    public function load(
        string $filePath,
        ?ConfigurationContainer $fallback = null,
        ?DefinitionGraph $graph = null,
        ?string $releaseIdentity = null,
    ): ProductionContainer {
        $artifactPath = $this->artifactPath($filePath);
        $manifest = new StaticRuntimeArtifactMetadata()->validate($artifactPath);
        $this->assertEnvironmentMatches($manifest, $fallback);
        $this->assertGraphMatches($manifest, $graph);
        $this->assertFallbackMatches($manifest, $fallback, $releaseIdentity);

        return $this->attachFallback($this->loadRuntime($artifactPath), $fallback);
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
        ?ConfigurationContainer $fallback = null,
        ?DefinitionGraph $graph = null,
        ?string $releaseIdentity = null,
    ): ProductionContainer {
        $this->assertDigest($expectedDigest);
        $artifactPath = $this->artifactPath($filePath);
        $manifest = new StaticRuntimeArtifactMetadata()->readCompatible($artifactPath);
        if (!hash_equals($manifest['digest'], $expectedDigest)) {
            throw new ContainerException(
                'Prevalidated static runtime does not match the active deployment digest.',
            );
        }
        $this->assertEnvironmentMatches($manifest, $fallback);
        $this->assertGraphMatches($manifest, $graph);
        $this->assertFallbackMatches($manifest, $fallback, $releaseIdentity);

        return $this->attachFallback($this->loadRuntime($artifactPath), $fallback);
    }

    private function activateBuild(string $filePath, string $buildDirectory): void
    {
        $directory = realpath(dirname($filePath));
        if ($directory === false || !is_dir($directory)) {
            throw new ContainerException("Output directory does not exist for '$filePath'.");
        }

        $temporaryLink = tempnam($directory, '.intermix-active-');
        if ($temporaryLink === false) {
            throw new ContainerException("Unable to create activation pointer for '$filePath'.");
        }
        unlink($temporaryLink);

        $target = $buildDirectory . DIRECTORY_SEPARATOR . StaticRuntimeArtifactMetadata::RUNTIME_NAME;

        try {
            if (!symlink($target, $temporaryLink)) {
                throw new ContainerException("Unable to stage activation pointer for '$filePath'.");
            }
            if (!rename($temporaryLink, $filePath)) {
                throw new ContainerException("Unable to atomically activate static runtime '$filePath'.");
            }
        } finally {
            if (is_link($temporaryLink) || is_file($temporaryLink)) {
                unlink($temporaryLink);
            }
        }
    }

    private function artifactPath(string $filePath): string
    {
        if (!is_link($filePath)) {
            throw new ContainerException(
                'Static runtime activation pointer is stale or incompatible with InterMix 11.',
            );
        }

        $artifactPath = realpath($filePath);
        if ($artifactPath === false || !is_file($artifactPath) || !is_readable($artifactPath)) {
            throw new ContainerException("Static runtime artifact is not readable: '$filePath'.");
        }

        return $artifactPath;
    }

    private function assertDigest(string $digest): void
    {
        if (preg_match('/^[a-f0-9]{32}$/D', $digest) !== 1) {
            throw new ContainerException(
                'Prevalidated static runtime digest must be a lowercase xxh128 hexadecimal value.',
            );
        }
    }

    /**
     * @param array{
     *   abi: int,
     *   intermix_major: int,
     *   php: string,
     *   digest: string,
     *   graph: string,
     *   environment: ?string,
     *   compiled: list<string>,
     *   skipped: array<string, string>,
     *   fallback: array{required: bool, identity_required: bool, ids: list<string>, release_identity: ?string},
     *   artifact: string,
     *   build: string
     * } $manifest
     */
    private function assertEnvironmentMatches(
        array $manifest,
        ?ConfigurationContainer $fallback,
    ): void {
        if (!$fallback instanceof ConfigurationContainer) {
            return;
        }

        $environment = $fallback->getRepository()->getEnvironment();
        if ($manifest['environment'] !== $environment) {
            throw new ContainerException(
                'Static runtime environment does not match the configured container environment.',
            );
        }
    }

    /**
     * @param array{
     *   abi: int,
     *   intermix_major: int,
     *   php: string,
     *   digest: string,
     *   graph: string,
     *   environment: ?string,
     *   compiled: list<string>,
     *   skipped: array<string, string>,
     *   fallback: array{required: bool, identity_required: bool, ids: list<string>, release_identity: ?string},
     *   artifact: string,
     *   build: string
     * } $manifest
     */
    private function assertFallbackMatches(
        array $manifest,
        ?ConfigurationContainer $fallback,
        ?string $releaseIdentity,
    ): void {
        if (!$manifest['fallback']['required']) {
            return;
        }
        if (!$fallback instanceof ConfigurationContainer) {
            throw new ContainerException(
                'Static runtime requires its frozen fallback graph.',
            );
        }

        if ($manifest['fallback']['identity_required']) {
            $expectedIdentity = $manifest['fallback']['release_identity'];
            if (!is_string($expectedIdentity) || $releaseIdentity === null || $releaseIdentity === '') {
                throw new ContainerException(
                    'Static runtime fallback requires an explicit matching release identity.',
                );
            }
            if (!hash_equals($expectedIdentity, hash('xxh128', $releaseIdentity))) {
                throw new ContainerException(
                    'Static runtime fallback release identity does not match the artifact.',
                );
            }
        }

        foreach ($manifest['fallback']['ids'] as $id) {
            if (!$fallback->has($id)) {
                throw new ContainerException(
                    "Static runtime fallback is missing required entry '$id'.",
                );
            }
        }
    }

    /**
     * @param array{
     *   abi: int,
     *   intermix_major: int,
     *   php: string,
     *   digest: string,
     *   graph: string,
     *   environment: ?string,
     *   compiled: list<string>,
     *   skipped: array<string, string>,
     *   fallback: array{required: bool, identity_required: bool, ids: list<string>, release_identity: ?string},
     *   artifact: string,
     *   build: string
     * } $manifest
     */
    private function assertGraphMatches(array $manifest, ?DefinitionGraph $graph): void
    {
        if (!$graph instanceof DefinitionGraph) {
            return;
        }

        $planned = new StaticRuntimePlanner()->plan($graph);
        $identity = new StaticRuntimeArtifactMetadata()->graphIdentity(
            $graph,
            $planned['plans'],
            $planned['skipped'],
        );
        if (!hash_equals($manifest['graph'], $identity)) {
            throw new ContainerException(
                'Static runtime graph identity does not match the configured frozen graph.',
            );
        }
    }

    private function attachFallback(
        ProductionContainer $runtime,
        ?ConfigurationContainer $fallback,
    ): ProductionContainer {
        if ($fallback instanceof ConfigurationContainer) {
            ProductionContainerAccess::attachFallback($runtime, $fallback);
        }

        return $runtime;
    }

    private function buildRoot(string $filePath): string
    {
        return $filePath . '.builds';
    }

    private function cleanupDirectory(string $directory): void
    {
        if (!is_dir($directory)) {
            return;
        }

        foreach (scandir($directory) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $path = $directory . DIRECTORY_SEPARATOR . $entry;
            if (is_dir($path) && !is_link($path)) {
                $this->cleanupDirectory($path);
            } else {
                unlink($path);
            }
        }

        rmdir($directory);
    }

    private function createStagingDirectory(string $root): string
    {
        $staging = $root . DIRECTORY_SEPARATOR . '.staging-' . bin2hex(random_bytes(8));
        if (!mkdir($staging, 0755)) {
            throw new ContainerException("Unable to create static runtime staging directory '$staging'.");
        }

        return $staging;
    }

    private function existingBuildDirectory(string $buildDirectory, string $build): ?string
    {
        if (!is_dir($buildDirectory)) {
            return null;
        }

        $runtimePath = $buildDirectory . DIRECTORY_SEPARATOR . StaticRuntimeArtifactMetadata::RUNTIME_NAME;
        $manifestPath = $buildDirectory . DIRECTORY_SEPARATOR . StaticRuntimeArtifactMetadata::MANIFEST_NAME;
        if (!is_file($runtimePath) || !is_file($manifestPath)) {
            throw new ContainerException(
                "Static runtime build '$build' exists but is incomplete.",
            );
        }

        return $buildDirectory;
    }

    private function loadRuntime(string $artifactPath): ProductionContainer
    {
        if (!is_file($artifactPath) || !is_readable($artifactPath)) {
            throw new ContainerException("Static runtime artifact is not readable: '$artifactPath'.");
        }

        $runtime = require $artifactPath;
        if (!$runtime instanceof ProductionContainer) {
            throw new ContainerException('Static runtime artifact must return a production container.');
        }

        return $runtime;
    }

    private function prepareBuildRoot(string $filePath): string
    {
        if (!is_dir(dirname($filePath))) {
            throw new ContainerException("Output directory does not exist for '$filePath'.");
        }

        $root = $this->buildRoot($filePath);
        if (!is_dir($root) && !mkdir($root, 0755, true) && !is_dir($root)) {
            throw new ContainerException("Unable to create static runtime build root '$root'.");
        }

        return $root;
    }

    private function publishStagedBuild(
        string $staging,
        string $buildDirectory,
        string $build,
    ): void {
        if (!rename($staging, $buildDirectory)) {
            throw new ContainerException(
                "Unable to atomically publish static runtime build '$build'.",
            );
        }
    }

    private function stageBuild(
        string $filePath,
        string $source,
        string $manifest,
        string $build,
    ): string {
        $root = $this->prepareBuildRoot($filePath);
        $buildDirectory = $root . DIRECTORY_SEPARATOR . $build;
        $existing = $this->existingBuildDirectory($buildDirectory, $build);
        if ($existing !== null) {
            return $existing;
        }

        $staging = $this->createStagingDirectory($root);

        try {
            $this->writeStagedBuild($staging, $source, $manifest);
            $this->publishStagedBuild($staging, $buildDirectory, $build);
        } finally {
            $this->cleanupDirectory($staging);
        }

        return $buildDirectory;
    }

    private function validateStagedManifest(string $temporaryPath): void
    {
        $contents = file_get_contents($temporaryPath);
        if (!is_string($contents)) {
            throw new ContainerException('Unable to validate static runtime manifest.');
        }

        try {
            $decoded = json_decode($contents, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new ContainerException(
                'Static runtime manifest is invalid JSON.',
                previous: $exception,
            );
        }

        if (!is_array($decoded)) {
            throw new ContainerException('Static runtime manifest must decode to an object.');
        }
    }

    private function writeStagedBuild(
        string $staging,
        string $source,
        string $manifest,
    ): void {
        AtomicFileWriter::write(
            $staging . DIRECTORY_SEPARATOR . StaticRuntimeArtifactMetadata::RUNTIME_NAME,
            $source,
            function (string $temporaryPath): void {
                $this->loadRuntime($temporaryPath);
            },
        );

        AtomicFileWriter::write(
            $staging . DIRECTORY_SEPARATOR . StaticRuntimeArtifactMetadata::MANIFEST_NAME,
            $manifest,
            $this->validateStagedManifest(...),
        );
    }
}
