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
    private const int ARTIFACT_ABI = 2;

    private const int INTERMIX_MAJOR = 11;

    private const string MANIFEST_NAME = 'manifest.json';

    private const string RUNTIME_NAME = 'runtime.php';

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
        $graphIdentity = $this->graphIdentity($graph, $plans, $planned['skipped']);
        $fallbackMetadata = $this->fallbackMetadata(
            $graph,
            $planned['skipped'],
            $releaseIdentity,
        );
        $manifest = [
            'abi' => self::ARTIFACT_ABI,
            'intermix_major' => self::INTERMIX_MAJOR,
            'php' => PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION,
            'digest' => $digest,
            'graph' => $graphIdentity,
            'environment' => $graph->environment(),
            'compiled' => $compiled,
            'skipped' => $planned['skipped'],
            'fallback' => $fallbackMetadata,
            'artifact' => self::RUNTIME_NAME,
        ];
        $build = $this->buildId($manifest);
        $manifest['build'] = $build;
        $manifestJson = $this->encodeManifest($manifest);

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
        $manifest = $this->validateManifest($artifactPath);
        $this->assertGraphMatches($manifest, $graph);
        $this->assertEnvironmentMatches($manifest, $fallback);
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
        $manifest = $this->readManifest($artifactPath);
        $this->assertManifestCompatibility($manifest, $artifactPath);
        if (!hash_equals($manifest['digest'], $expectedDigest)) {
            throw new ContainerException(
                'Prevalidated static runtime does not match the active deployment digest.',
            );
        }
        $this->assertGraphMatches($manifest, $graph);
        $this->assertEnvironmentMatches($manifest, $fallback);
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

        $target = $buildDirectory . DIRECTORY_SEPARATOR . self::RUNTIME_NAME;
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
     *   fallback: array{required: bool, ids: list<string>, release_identity: ?string},
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
     *   fallback: array{required: bool, ids: list<string>, release_identity: ?string},
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
     *   fallback: array{required: bool, ids: list<string>, release_identity: ?string},
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
        $identity = $this->graphIdentity($graph, $planned['plans'], $planned['skipped']);
        if (!hash_equals($manifest['graph'], $identity)) {
            throw new ContainerException(
                'Static runtime graph identity does not match the configured frozen graph.',
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
     *   fallback: array{required: bool, ids: list<string>, release_identity: ?string},
     *   artifact: string,
     *   build: string
     * } $manifest
     */
    private function assertManifestCompatibility(array $manifest, string $artifactPath): void
    {
        if ($manifest['abi'] !== self::ARTIFACT_ABI) {
            throw new ContainerException(
                "Unsupported static runtime ABI '{$manifest['abi']}'.",
            );
        }
        if ($manifest['intermix_major'] !== self::INTERMIX_MAJOR) {
            throw new ContainerException(
                'Static runtime was generated for a different InterMix major.',
            );
        }

        $php = PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION;
        if ($manifest['php'] !== $php) {
            throw new ContainerException(
                "Static runtime targets PHP {$manifest['php']}; active runtime is PHP {$php}.",
            );
        }
        if ($manifest['artifact'] !== basename($artifactPath)) {
            throw new ContainerException('Static runtime manifest does not identify the active artifact.');
        }
        if ($manifest['build'] !== basename(dirname($artifactPath))) {
            throw new ContainerException('Static runtime activation pointer does not match its build manifest.');
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

    /** @param array<string, mixed> $manifest */
    private function buildId(array $manifest): string
    {
        try {
            $encoded = json_encode(
                $this->canonicalize($manifest),
                JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
            );
        } catch (JsonException $exception) {
            throw new ContainerException('Unable to encode static runtime build identity.', previous: $exception);
        }

        return hash('xxh128', $encoded);
    }

    private function buildRoot(string $filePath): string
    {
        return $filePath . '.builds';
    }

    private function canonicalize(mixed $value): mixed
    {
        if ($value instanceof \UnitEnum) {
            return $value->name;
        }
        if (!is_array($value)) {
            return $value;
        }
        if (array_is_list($value)) {
            return array_map(fn(mixed $entry): mixed => $this->canonicalize($entry), $value);
        }

        ksort($value, SORT_STRING);
        foreach ($value as $key => $entry) {
            $value[$key] = $this->canonicalize($entry);
        }

        return $value;
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

    /**
     * @param array<string, mixed> $manifest
     */
    private function encodeManifest(array $manifest): string
    {
        try {
            return json_encode(
                $manifest,
                JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
            ) . "\n";
        } catch (JsonException $exception) {
            throw new ContainerException('Unable to encode static runtime manifest.', previous: $exception);
        }
    }

    /**
     * @param array<string, string> $skipped
     * @return array{required: bool, ids: list<string>, release_identity: ?string}
     */
    private function fallbackMetadata(
        DefinitionGraph $graph,
        array $skipped,
        ?string $releaseIdentity,
    ): array {
        $defined = array_fill_keys(
            array_map(
                static fn(int|string $id): string => (string) $id,
                array_keys($graph->definitions()),
            ),
            true,
        );
        $ids = [];
        foreach ([
            ...array_keys($skipped),
            ...$graph->dynamicServiceIds(),
            ...$graph->resolvingHookIds(),
            ...$graph->resolvedHookIds(),
        ] as $rawId) {
            $id = (string) $rawId;
            if (isset($defined[$id])) {
                $ids[$id] = true;
            }
        }
        $ids = array_keys($ids);
        sort($ids, SORT_STRING);

        $required = $skipped !== [] || $graph->requiresReleaseIdentity();

        return [
            'required' => $required,
            'ids' => $ids,
            'release_identity' => $releaseIdentity === null || $releaseIdentity === ''
                ? null
                : hash('xxh128', $releaseIdentity),
        ];
    }

    /**
     * @param array<string, array<string, mixed>> $plans
     * @param array<string, string> $skipped
     */
    private function graphIdentity(
        DefinitionGraph $graph,
        array $plans,
        array $skipped,
    ): string {
        $definitionMeta = $graph->definitionMeta();
        foreach ($definitionMeta as &$meta) {
            sort($meta['tags'], SORT_STRING);
            $meta['lifetime'] = $meta['lifetime']->name;
        }
        unset($meta);
        ksort($definitionMeta, SORT_STRING);
        ksort($plans, SORT_STRING);
        ksort($skipped, SORT_STRING);

        $attributes = $graph->registeredAttributeTypes();
        sort($attributes, SORT_STRING);
        $dynamicIds = $graph->dynamicServiceIds();
        sort($dynamicIds, SORT_STRING);
        $resolvedHooks = $graph->resolvedHookIds();
        sort($resolvedHooks, SORT_STRING);
        $resolvingHooks = $graph->resolvingHookIds();
        sort($resolvingHooks, SORT_STRING);
        $scopeHooks = $graph->scopeLeaveHookScopes();
        sort($scopeHooks, SORT_STRING);

        $identity = [
            'environment' => $graph->environment(),
            'plans' => $plans,
            'skipped' => $skipped,
            'definition_meta' => $definitionMeta,
            'contextual_shape' => $graph->contextualBindingShape(),
            'attribute_types' => $attributes,
            'dynamic_ids' => $dynamicIds,
            'resolved_hooks' => $resolvedHooks,
            'resolving_hooks' => $resolvingHooks,
            'scope_hooks' => $scopeHooks,
            'injection' => $graph->injectionEnabled(),
            'method_attributes' => $graph->methodAttributesEnabled(),
            'property_attributes' => $graph->propertyAttributesEnabled(),
            'default_method' => $graph->defaultMethod(),
        ];

        try {
            $encoded = json_encode(
                $this->canonicalize($identity),
                JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
            );
        } catch (JsonException $exception) {
            throw new ContainerException('Unable to encode static runtime graph identity.', previous: $exception);
        }

        return hash('xxh128', $encoded);
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

    /**
     * @return array{
     *   abi: int,
     *   intermix_major: int,
     *   php: string,
     *   digest: string,
     *   graph: string,
     *   environment: ?string,
     *   compiled: list<string>,
     *   skipped: array<string, string>,
     *   fallback: array{required: bool, ids: list<string>, release_identity: ?string},
     *   artifact: string,
     *   build: string
     * }
     */
    private function readManifest(string $artifactPath): array
    {
        $manifestPath = dirname($artifactPath) . DIRECTORY_SEPARATOR . self::MANIFEST_NAME;
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
            || !isset(
                $manifest['abi'],
                $manifest['intermix_major'],
                $manifest['php'],
                $manifest['digest'],
                $manifest['graph'],
                $manifest['compiled'],
                $manifest['skipped'],
                $manifest['fallback'],
                $manifest['artifact'],
                $manifest['build'],
            )
            || !array_key_exists('environment', $manifest)
            || !is_int($manifest['abi'])
            || !is_int($manifest['intermix_major'])
            || !is_string($manifest['php'])
            || !is_string($manifest['digest'])
            || preg_match('/^[a-f0-9]{32}$/D', $manifest['digest']) !== 1
            || !is_string($manifest['graph'])
            || preg_match('/^[a-f0-9]{32}$/D', $manifest['graph']) !== 1
            || (!is_string($manifest['environment']) && $manifest['environment'] !== null)
            || !is_array($manifest['compiled'])
            || !is_array($manifest['skipped'])
            || !is_array($manifest['fallback'])
            || !isset($manifest['fallback']['required'], $manifest['fallback']['ids'])
            || !array_key_exists('release_identity', $manifest['fallback'])
            || !is_bool($manifest['fallback']['required'])
            || !is_array($manifest['fallback']['ids'])
            || (!is_string($manifest['fallback']['release_identity'])
                && $manifest['fallback']['release_identity'] !== null)
            || !is_string($manifest['artifact'])
            || !is_string($manifest['build'])
            || preg_match('/^[a-f0-9]{32}$/D', $manifest['build']) !== 1
        ) {
            throw new ContainerException('Static runtime manifest has an invalid shape.');
        }

        foreach ($manifest['compiled'] as $id) {
            if (!is_string($id)) {
                throw new ContainerException('Static runtime manifest has invalid compiled IDs.');
            }
        }
        foreach ($manifest['skipped'] as $id => $reason) {
            if (!is_string($id) || !is_string($reason)) {
                throw new ContainerException('Static runtime manifest has invalid skipped entries.');
            }
        }
        foreach ($manifest['fallback']['ids'] as $id) {
            if (!is_string($id)) {
                throw new ContainerException('Static runtime manifest has invalid fallback IDs.');
            }
        }

        /** @var array{
         *   abi: int,
         *   intermix_major: int,
         *   php: string,
         *   digest: string,
         *   graph: string,
         *   environment: ?string,
         *   compiled: list<string>,
         *   skipped: array<string, string>,
         *   fallback: array{required: bool, ids: list<string>, release_identity: ?string},
         *   artifact: string,
         *   build: string
         * } $manifest
         */
        return $manifest;
    }

    private function stageBuild(
        string $filePath,
        string $source,
        string $manifest,
        string $build,
    ): string {
        $root = $this->buildRoot($filePath);
        if (!is_dir($root) && !mkdir($root, 0755, true) && !is_dir($root)) {
            throw new ContainerException("Unable to create static runtime build root '$root'.");
        }

        $buildDirectory = $root . DIRECTORY_SEPARATOR . $build;
        if (is_dir($buildDirectory)) {
            $runtimePath = $buildDirectory . DIRECTORY_SEPARATOR . self::RUNTIME_NAME;
            $manifestPath = $buildDirectory . DIRECTORY_SEPARATOR . self::MANIFEST_NAME;
            if (!is_file($runtimePath) || !is_file($manifestPath)) {
                throw new ContainerException(
                    "Static runtime build '$build' exists but is incomplete.",
                );
            }

            return $buildDirectory;
        }

        $staging = $root . DIRECTORY_SEPARATOR . '.staging-' . bin2hex(random_bytes(8));
        if (!mkdir($staging, 0755)) {
            throw new ContainerException("Unable to create static runtime staging directory '$staging'.");
        }

        try {
            AtomicFileWriter::write(
                $staging . DIRECTORY_SEPARATOR . self::RUNTIME_NAME,
                $source,
                function (string $temporaryPath): void {
                    $this->loadRuntime($temporaryPath);
                },
            );
            AtomicFileWriter::write(
                $staging . DIRECTORY_SEPARATOR . self::MANIFEST_NAME,
                $manifest,
                static function (string $temporaryPath): void {
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
                },
            );

            if (!rename($staging, $buildDirectory)) {
                throw new ContainerException(
                    "Unable to atomically publish static runtime build '$build'.",
                );
            }
        } finally {
            $this->cleanupDirectory($staging);
        }

        return $buildDirectory;
    }

    /**
     * @return array{
     *   abi: int,
     *   intermix_major: int,
     *   php: string,
     *   digest: string,
     *   graph: string,
     *   environment: ?string,
     *   compiled: list<string>,
     *   skipped: array<string, string>,
     *   fallback: array{required: bool, ids: list<string>, release_identity: ?string},
     *   artifact: string,
     *   build: string
     * }
     */
    private function validateManifest(string $artifactPath): array
    {
        $manifest = $this->readManifest($artifactPath);
        $this->assertManifestCompatibility($manifest, $artifactPath);

        $hash = hash_file('xxh128', $artifactPath);
        if (!is_string($hash) || !hash_equals($manifest['digest'], $hash)) {
            throw new ContainerException('Static runtime artifact hash does not match its manifest.');
        }

        return $manifest;
    }
}
