<?php

declare(strict_types=1);

namespace Infocyph\InterMix\DI\Build;

use Infocyph\InterMix\DI\Container;
use Infocyph\InterMix\DI\Support\AliasDefinition;
use Infocyph\InterMix\DI\Support\AutowireDefinition;
use Infocyph\InterMix\DI\Support\FactoryDefinition;
use Infocyph\InterMix\DI\Support\InputDefinition;
use Infocyph\InterMix\DI\Support\RuntimeFactoryDefinition;
use Infocyph\InterMix\DI\Support\ServiceReference;
use Infocyph\InterMix\DI\Support\ValueDefinition;
use Infocyph\InterMix\Exceptions\ContainerException;
use JsonException;

/**
 * Owns the InterMix 11 generated-runtime manifest and frozen graph identity.
 *
 * @internal
 */
final class StaticRuntimeArtifactMetadata
{
    public const int ABI = 2;

    public const int INTERMIX_MAJOR = 11;

    public const string MANIFEST_NAME = 'manifest.json';

    public const string RUNTIME_NAME = 'runtime.php';

/** @param array<string, mixed> $manifest */
    public function encode(array $manifest): string
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
     * @param array<string, mixed> $plans
     * @param array<string, string> $skipped
     * @return array{
     *   required: bool,
     *   identity_required: bool,
     *   ids: list<string>,
     *   release_identity: ?string
     * }
     */
    public function fallbackMetadata(
        DefinitionGraph $graph,
        array $plans,
        array $skipped,
        ?string $releaseIdentity,
    ): array {
        /** @var array<string, array{kind: string, properties: list<array{declaring: class-string, property: string, static: bool, argument: array{kind: 'service', id: string}|array{kind: 'value', code: string}|null, runtime?: 'assign'|'attribute'}>, postMethod?: array{method: string, arguments: list<array{kind: 'service', id: string}|array{kind: 'value', code: string}>, dependencies: list<string>, parameterNames?: list<string>, static?: bool, bound?: bool, runtime?: bool}|null, invocation?: array{method: string, arguments: list<array{kind: 'service', id: string}|array{kind: 'value', code: string}>, dependencies: list<string>, parameterNames?: list<string>, static?: bool, bound?: bool, runtime?: bool}}> $plans */
        $reasons = new StaticRuntimeRequirements()->fallbackReasons($graph, $plans, $skipped);
        $defined = array_fill_keys(
            array_map(
                static fn(int|string $id): string => (string) $id,
                array_keys($graph->definitions()),
            ),
            true,
        );
        $ids = array_values(array_filter(
            array_keys($reasons),
            static fn(string $id): bool => isset($defined[$id]),
        ));
        sort($ids, SORT_STRING);

        $identityRequired = $graph->requiresReleaseIdentity();

        return [
            'required' => $reasons !== [],
            'identity_required' => $identityRequired,
            'ids' => $ids,
            'release_identity' => !$identityRequired
                || $releaseIdentity === null
                || $releaseIdentity === ''
                ? null
                : hash('xxh128', $releaseIdentity),
        ];
    }

/**
     * @param array<string, array<string, mixed>> $plans
     * @param array<string, string> $skipped
     */
    public function graphIdentity(
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

        $attributes = $graph->registeredAttributeResolvers();
        $environmentBindings = $graph->environmentBindings();
        $dynamicIds = $graph->dynamicServiceIds();
        $resolvedHooks = $graph->resolvedHookIds();
        $resolvingHooks = $graph->resolvingHookIds();
        $scopeHooks = $graph->scopeLeaveHookScopes();
        ksort($attributes, SORT_STRING);
        ksort($environmentBindings, SORT_STRING);
        sort($dynamicIds, SORT_STRING);
        sort($resolvedHooks, SORT_STRING);
        sort($resolvingHooks, SORT_STRING);
        sort($scopeHooks, SORT_STRING);

        return $this->hashIdentity([
            'environment' => $graph->environment(),
            'environment_bindings' => $environmentBindings,
            'plans' => $plans,
            'skipped' => $skipped,
            'definitions' => $this->identityValue($graph->definitions()),
            'definition_meta' => $definitionMeta,
            'contextual_bindings' => $this->identityValue($graph->contextualBindings()),
            'attribute_resolvers' => $attributes,
            'dynamic_ids' => $dynamicIds,
            'resolved_hooks' => $resolvedHooks,
            'resolving_hooks' => $resolvingHooks,
            'scope_hooks' => $scopeHooks,
            'injection' => $graph->injectionEnabled(),
            'method_attributes' => $graph->methodAttributesEnabled(),
            'property_attributes' => $graph->propertyAttributesEnabled(),
            'default_method' => $graph->defaultMethod(),
        ]);
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
     *   fallback: array{required: bool, identity_required: bool, ids: list<string>, release_identity: ?string},
     *   artifact: string,
     *   build: string
     * }
     */
    public function readCompatible(string $artifactPath): array
    {
        $manifest = $this->read($artifactPath);
        $this->assertCompatibility($manifest, $artifactPath);

        return $manifest;
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
     *   fallback: array{required: bool, identity_required: bool, ids: list<string>, release_identity: ?string},
     *   artifact: string,
     *   build: string
     * }
     */
    public function validate(string $artifactPath): array
    {
        $manifest = $this->readCompatible($artifactPath);
        $hash = hash_file('xxh128', $artifactPath);
        if (!is_string($hash) || !hash_equals($manifest['digest'], $hash)) {
            throw new ContainerException('Static runtime artifact hash does not match its manifest.');
        }

        return $manifest;
    }

/**
     * @param array<string, mixed> $manifest
     * @return array<string, mixed>
     */
    public function withBuildIdentity(array $manifest): array
    {
        $manifest['build'] = $this->hashIdentity($manifest);

        return $manifest;
    }

/** @param array<string, mixed> $manifest */
    private function assertCompatibility(array $manifest, string $artifactPath): void
    {
        if ($manifest['abi'] !== self::ABI) {
            throw new ContainerException("Unsupported static runtime ABI '{$manifest['abi']}'.");
        }
        if ($manifest['intermix_major'] !== self::INTERMIX_MAJOR) {
            throw new ContainerException('Static runtime was generated for a different InterMix major.');
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

/** @param array<string, mixed> $manifest */
    private function assertEntries(array $manifest): void
    {
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
    }

/** @param array<string, mixed> $manifest */
    private function assertShape(array $manifest): void
    {
        if (!isset(
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
        ) || !array_key_exists('environment', $manifest)) {
            throw new ContainerException('Static runtime manifest has an invalid shape.');
        }
        if (!$this->hasScalarShape($manifest) || !$this->hasFallbackShape($manifest)) {
            throw new ContainerException('Static runtime manifest has an invalid shape.');
        }
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
            return array_map($this->canonicalize(...), $value);
        }

        ksort($value, SORT_STRING);
        foreach ($value as $key => $entry) {
            $value[$key] = $this->canonicalize($entry);
        }

        return $value;
    }

/** @param array<string, mixed> $manifest */
    private function hasFallbackShape(array $manifest): bool
    {
        $fallback = $manifest['fallback'];
        if (!is_array($fallback)
            || !isset($fallback['required'], $fallback['identity_required'], $fallback['ids'])
            || !array_key_exists('release_identity', $fallback)
        ) {
            return false;
        }

        return is_bool($fallback['required'])
            && is_bool($fallback['identity_required'])
            && is_array($fallback['ids'])
            && (is_string($fallback['release_identity']) || $fallback['release_identity'] === null);
    }

/** @param array<string, mixed> $value */
    private function hashIdentity(array $value): string
    {
        try {
            $encoded = json_encode(
                $this->canonicalize($value),
                JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
            );
        } catch (JsonException $exception) {
            throw new ContainerException('Unable to encode static runtime identity.', previous: $exception);
        }

        return hash('xxh128', $encoded);
    }

/** @param array<string, mixed> $manifest */
    private function hasScalarShape(array $manifest): bool
    {
        return is_int($manifest['abi'])
            && is_int($manifest['intermix_major'])
            && is_string($manifest['php'])
            && is_string($manifest['digest'])
            && preg_match('/^[a-f0-9]{32}$/D', $manifest['digest']) === 1
            && is_string($manifest['graph'])
            && preg_match('/^[a-f0-9]{32}$/D', $manifest['graph']) === 1
            && (is_string($manifest['environment']) || $manifest['environment'] === null)
            && is_array($manifest['compiled'])
            && is_array($manifest['skipped'])
            && is_string($manifest['artifact'])
            && is_string($manifest['build'])
            && preg_match('/^[a-f0-9]{32}$/D', $manifest['build']) === 1;
    }

/** @param array<string, mixed> $value */
    private function identityArray(array $value): array
    {
        $mapped = [];
        foreach ($value as $key => $entry) {
            $mapped[$key] = $this->identityValue($entry);
        }

        return $mapped;
    }

private function identityObject(object $value): array
    {
        return match (true) {
            $value instanceof FactoryDefinition => ['factory' => $value->signature()],
            $value instanceof ServiceReference => ['service' => $value->id],
            $value instanceof AliasDefinition => ['alias' => $value->target],
            $value instanceof InputDefinition => ['input' => true],
            $value instanceof ValueDefinition => ['value' => $this->identityValue($value->value)],
            $value instanceof AutowireDefinition => [
                'autowire' => $value->class,
                'arguments' => $this->identityValue($value->arguments),
                'properties' => $this->identityValue($value->properties),
            ],
            $value instanceof RuntimeFactoryDefinition => ['opaque' => 'runtime-factory'],
            $value instanceof Container => ['runtime' => 'container'],
            default => ['opaque' => $value::class],
        };
    }

private function identityValue(mixed $value): mixed
    {
        if (is_scalar($value) || $value === null) {
            return $value;
        }
        if (is_array($value)) {
            return $this->identityArray($value);
        }
        if (is_object($value)) {
            return $this->identityObject($value);
        }

        return ['opaque' => get_debug_type($value)];
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
     *   fallback: array{required: bool, identity_required: bool, ids: list<string>, release_identity: ?string},
     *   artifact: string,
     *   build: string
     * }
     */
    private function read(string $artifactPath): array
    {
        $path = dirname($artifactPath) . DIRECTORY_SEPARATOR . self::MANIFEST_NAME;
        if (!is_file($path) || !is_readable($path)) {
            throw new ContainerException("Static runtime manifest is not readable: '$path'.");
        }

        $contents = file_get_contents($path);
        if (!is_string($contents)) {
            throw new ContainerException("Unable to read static runtime manifest: '$path'.");
        }

        try {
            $manifest = json_decode($contents, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new ContainerException('Static runtime manifest is invalid JSON.', previous: $exception);
        }
        if (!is_array($manifest)) {
            throw new ContainerException('Static runtime manifest has an invalid shape.');
        }

        $this->assertShape($manifest);
        $this->assertEntries($manifest);

        /** @var array{
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
        return $manifest;
    }
}
