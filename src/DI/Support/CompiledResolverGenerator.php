<?php

declare(strict_types=1);

namespace Infocyph\InterMix\DI\Support;

use Closure;
use Composer\InstalledVersions;
use Infocyph\InterMix\DI\Container;
use Infocyph\InterMix\Exceptions\ContainerException;
use Infocyph\InterMix\Internal\AtomicFileWriter;
use Infocyph\InterMix\Internal\ReflectionResource;
use ReflectionClass;
use ReflectionException;
use ReflectionFunctionAbstract;

final class CompiledResolverGenerator
{
    private const int ARTIFACT_FORMAT = 3;

    /**
     * @param Container $container Fully registered source container.
     * @param string $filePath Trusted build-artifact destination.
     * @return array{
     *   resolver: Closure(Container, string): mixed,
     *   ids: array<string, string>,
     *   report: array{path: string, fingerprint: string, compiled: array<int, string>, skipped: array<string, string>}
     * }
     * @throws ContainerException|ReflectionException
     */
    public function generate(Container $container, string $filePath): array
    {
        $definitions = $container->getRepository()->getFunctionReference();
        ksort($definitions, SORT_STRING);

        $compiledCodeGroups = [];
        $compiledFingerprints = [];
        $compiledIds = [];
        $skipped = [];

        foreach ($definitions as $id => $definition) {
            $compiled = $this->compileEntry($container, $definition);
            if ($compiled['code'] === null || $compiled['signature'] === null) {
                $skipped[$id] = $compiled['reason'];

                continue;
            }

            $compiledCodeGroups[$compiled['code']][] = $id;
            $compiledFingerprints[$id] = self::stableHash($compiled['signature']);
            $compiledIds[] = $id;
        }

        $identity = $this->artifactIdentity($container, $compiledFingerprints);
        $fingerprint = self::stableHash($identity);
        $code = $this->renderArtifact($identity + ['fingerprint' => $fingerprint], $compiledCodeGroups);

        AtomicFileWriter::write(
            $filePath,
            $code,
            function (string $temporaryPath) use ($container): void {
                $this->load($container, $temporaryPath);
            },
        );

        return [
            ...$this->load($container, $filePath),
            'report' => [
                'path' => $filePath,
                'fingerprint' => $fingerprint,
                'compiled' => $compiledIds,
                'skipped' => $skipped,
            ],
        ];
    }

    /**
     * Validate a compiled artifact against a fully registered container.
     *
     * The runtime check reads normalized registration metadata. Expensive
     * validation stays in cache generation.
     *
     * @param Container $container Fully registered runtime container.
     * @param string $filePath Trusted compiled artifact path.
     * @return array{resolver: Closure(Container, string): mixed, ids: array<string, string>}
     * @throws ContainerException
     */
    public function load(Container $container, string $filePath): array
    {
        if (!is_file($filePath) || !is_readable($filePath)) {
            throw new ContainerException("Compiled resolver artifact is not readable: '$filePath'.");
        }

        $artifact = require $filePath;
        if (!is_array($artifact)) {
            throw new ContainerException('Compiled resolver file must return an artifact array.');
        }

        $metadata = $artifact['metadata'] ?? null;
        $resolver = $artifact['resolver'] ?? null;
        if (!is_array($metadata) || !$resolver instanceof Closure) {
            throw new ContainerException('Compiled resolver artifact is malformed.');
        }

        $compiledFingerprints = $metadata['compiled'] ?? null;
        $this->assertFingerprintMap($compiledFingerprints);
        if (!is_string($metadata['definitions'] ?? null)
            || !is_string($metadata['resolution'] ?? null)
        ) {
            throw new ContainerException('Compiled resolver fingerprints are missing or malformed.');
        }

        $expectedIdentity = $this->artifactIdentity($container, $compiledFingerprints);
        if (($metadata['format'] ?? null) !== self::ARTIFACT_FORMAT
            || ($metadata['php'] ?? null) !== $expectedIdentity['php']
            || ($metadata['intermix'] ?? null) !== $expectedIdentity['intermix']
            || ($metadata['environment'] ?? null) !== $expectedIdentity['environment']
            || $metadata['definitions'] !== $expectedIdentity['definitions']
            || $metadata['resolution'] !== $expectedIdentity['resolution']
            || ($metadata['fingerprint'] ?? null) !== self::stableHash($expectedIdentity)
        ) {
            throw new ContainerException(
                'Compiled resolver artifact is stale or incompatible; rebuild it after registration.',
            );
        }

        return ['resolver' => $resolver, 'ids' => $compiledFingerprints];
    }

    /**
     * Load an artifact whose complete identity was validated during deployment.
     *
     * The expected fingerprint must come from the same immutable deployment
     * manifest as the artifact. This path still checks the artifact format, PHP
     * runtime, fingerprint, and callable payload before activation.
     *
     * @param string $filePath Trusted compiled artifact path.
     * @param string $expectedFingerprint Deployment-validated SHA-256 fingerprint.
     * @return array{resolver: Closure(Container, string): mixed, ids: array<string, string>}
     * @throws ContainerException
     */
    public function loadPrevalidated(string $filePath, string $expectedFingerprint): array
    {
        if (preg_match('/^[a-f0-9]{64}$/D', $expectedFingerprint) !== 1) {
            throw new ContainerException('Prevalidated resolver fingerprint must be a SHA-256 hexadecimal digest.');
        }
        if (!is_file($filePath) || !is_readable($filePath)) {
            throw new ContainerException("Compiled resolver artifact is not readable: '$filePath'.");
        }

        $artifact = require $filePath;
        if (!is_array($artifact)) {
            throw new ContainerException('Compiled resolver file must return an artifact array.');
        }

        $metadata = $artifact['metadata'] ?? null;
        $resolver = $artifact['resolver'] ?? null;
        if (!is_array($metadata) || !$resolver instanceof Closure) {
            throw new ContainerException('Compiled resolver artifact is malformed.');
        }

        $ids = $metadata['compiled'] ?? null;
        $this->assertFingerprintMap($ids);
        if (($metadata['format'] ?? null) !== self::ARTIFACT_FORMAT
            || ($metadata['php'] ?? null) !== PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION
            || ($metadata['fingerprint'] ?? null) !== $expectedFingerprint
        ) {
            throw new ContainerException(
                'Prevalidated resolver artifact does not match the active deployment manifest.',
            );
        }

        return ['resolver' => $resolver, 'ids' => $ids];
    }

    /**
     * @param array<array-key, mixed> $value
     */
    private static function stableHash(array $value): string
    {
        return hash('sha256', serialize($value));
    }

    /**
     * @param Container $container Container providing environment and definitions.
     * @param array<string, string> $compiledFingerprints Compilable recipe fingerprints.
     * @return array{
     *   format: int,
     *   php: string,
     *   intermix: array{version: string, reference: string|null},
     *   environment: string|null,
     *   definitions: string,
     *   resolution: string,
     *   compiled: array<string, string>
     * }
     */
    private function artifactIdentity(Container $container, array $compiledFingerprints): array
    {
        ksort($compiledFingerprints, SORT_STRING);

        return [
            'format' => self::ARTIFACT_FORMAT,
            'php' => PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION,
            'intermix' => $this->packageIdentity(),
            'environment' => $container->getRepository()->getEnvironment(),
            'definitions' => $this->currentDefinitionsFingerprint($container),
            'resolution' => $this->currentResolutionFingerprint($container),
            'compiled' => $compiledFingerprints,
        ];
    }

    /**
     * @param mixed $value Untrusted artifact metadata value.
     * @phpstan-assert array<string, string> $value
     */
    private function assertFingerprintMap(mixed $value): void
    {
        if (!is_array($value)) {
            throw new ContainerException('Compiled resolver metadata is malformed.');
        }

        foreach ($value as $id => $fingerprint) {
            if (!is_string($id) || !is_string($fingerprint)) {
                throw new ContainerException('Compiled resolver metadata is malformed.');
            }
        }
    }

    /**
     * @param array<int|string, mixed> $definition
     * @return array{code: string|null, signature: array<string, mixed>|null, reason: string}
     */
    private function compileArrayDefinition(array $definition): array
    {
        if (!isset($definition[0]) || !is_string($definition[0]) || !class_exists($definition[0])) {
            return $this->skipped('array definition does not start with an existing class');
        }

        $reflection = ReflectionResource::getClassReflection($definition[0]);
        if (!$reflection->isInstantiable()) {
            return $this->skipped('array definition target is not instantiable');
        }

        $method = isset($definition[1]) && is_string($definition[1]) ? $definition[1] : null;
        if ($method !== null && !$reflection->hasMethod($method)) {
            return $this->skipped('array definition method does not exist');
        }

        $class = '\\' . ltrim($reflection->getName(), '\\');
        $methodCode = $method !== null ? var_export($method, true) : 'false';

        return [
            'code' => "\$c->make({$class}::class, {$methodCode})",
            'signature' => [
                'kind' => 'array',
                'class' => $reflection->getName(),
                'method' => $method,
            ],
            'reason' => '',
        ];
    }

    /**
     * @param Container $container Fully registered build-time container.
     * @param string $definition Registered class-string definition.
     * @return array{code: string|null, signature: array<string, mixed>|null, reason: string}
     */
    private function compileClassDefinition(Container $container, string $definition): array
    {
        return new AutomaticClassCompiler()->compile($container, $definition);
    }

    /**
     * @param Container $container Fully registered build-time container.
     * @param mixed $definition Registered container definition.
     * @return array{code: string|null, signature: array<string, mixed>|null, reason: string}
     */
    private function compileEntry(Container $container, mixed $definition): array
    {
        if ($definition instanceof FactoryDefinition) {
            return $this->compileFactoryDefinition($definition);
        }
        if ($definition instanceof DirectFactory) {
            return $this->skipped('direct factories retain their runtime closure');
        }
        if ($definition instanceof Closure) {
            return $this->skipped('closures retain their runtime capture and reflection semantics');
        }
        if (is_array($definition)) {
            return $this->compileArrayDefinition($definition);
        }
        if (!is_string($definition) || !class_exists($definition)) {
            return $this->skipped('literal or non-class definition does not require compilation');
        }

        return $this->compileClassDefinition($container, $definition);
    }

    /**
     * @param FactoryDefinition $definition Explicit declarative recipe.
     * @return array{code: string|null, signature: array<string, mixed>|null, reason: string}
     */
    private function compileFactoryDefinition(FactoryDefinition $definition): array
    {
        if (!class_exists($definition->class)) {
            return $this->skipped('declarative factory class does not exist');
        }

        $class = ReflectionResource::getClassReflection($definition->class);
        $target = $this->factoryTarget($class, $definition->method);
        if ($target['issue'] !== null) {
            return $this->skipped($target['issue']);
        }

        $argumentIssue = $this->factoryArgumentIssue($target['callable'], count($definition->arguments));
        if ($argumentIssue !== null) {
            return $this->skipped($argumentIssue);
        }

        $arguments = [];
        foreach ($definition->arguments as $argument) {
            $arguments[] = $argument instanceof ServiceReference
                ? '$c->get(' . var_export($argument->id, true) . ')'
                : var_export($argument, true);
        }

        $fqcn = '\\' . ltrim($class->getName(), '\\');
        $args = implode(', ', $arguments);
        $code = $definition->method === null
            ? "new {$fqcn}({$args})"
            : "{$fqcn}::{$definition->method}({$args})";

        return [
            'code' => $code,
            'signature' => ['kind' => 'declarative-factory', 'recipe' => $definition->signature()],
            'reason' => '',
        ];
    }

    private function currentDefinitionsFingerprint(Container $container): string
    {
        $definitions = $container->getRepository()->getFunctionReference();
        ksort($definitions, SORT_STRING);
        $signatures = [];

        foreach ($definitions as $id => $definition) {
            $signature = $this->runtimeDefinitionSignature($definition);
            if ($signature === null) {
                continue;
            }
            $metadata = $container->getRepository()->getDefinitionMeta($id);
            $signatures[$id] = [
                'definition' => $signature,
                'lifetime' => $metadata['lifetime']->value,
                'tags' => $metadata['tags'],
            ];
        }

        return self::stableHash($signatures);
    }

    private function currentResolutionFingerprint(Container $container): string
    {
        $repository = $container->getRepository();
        $definitionIds = array_keys($repository->getFunctionReference());
        sort($definitionIds, SORT_STRING);

        $classResourceShape = [];
        foreach ($repository->getClassResource() as $class => $resources) {
            $resourceTypes = array_keys($resources);
            sort($resourceTypes, SORT_STRING);
            $classResourceShape[$class] = $resourceTypes;
        }
        ksort($classResourceShape, SORT_STRING);

        return self::stableHash([
            'definition_ids' => $definitionIds,
            'class_resources' => $classResourceShape,
            'contextual_bindings' => $repository->getContextualBindingShape(),
            'attribute_types' => $repository->getRegisteredAttributeTypes(),
            'method_attributes' => $repository->isMethodAttributeEnabled(),
            'property_attributes' => $repository->isPropertyAttributeEnabled(),
            'default_method' => $repository->getDefaultMethod(),
        ]);
    }

    private function factoryArgumentIssue(?ReflectionFunctionAbstract $target, int $argumentCount): ?string
    {
        if ($target === null) {
            return $argumentCount === 0
                ? null
                : 'declarative factory target has no constructor but arguments were provided';
        }
        if ($argumentCount < $target->getNumberOfRequiredParameters()
            || (!$target->isVariadic() && $argumentCount > $target->getNumberOfParameters())
        ) {
            return 'declarative factory argument count does not match its target';
        }

        return null;
    }

    /**
     * @param ReflectionClass<object> $class
     * @param string|null $method Requested static factory method.
     * @return array{callable: ReflectionFunctionAbstract|null, issue: string|null}
     */
    private function factoryTarget(ReflectionClass $class, ?string $method): array
    {
        if ($method === null) {
            return $class->isInstantiable()
                ? ['callable' => $class->getConstructor(), 'issue' => null]
                : ['callable' => null, 'issue' => 'declarative factory target is not instantiable'];
        }
        if (!$class->hasMethod($method)) {
            return ['callable' => null, 'issue' => 'declarative static factory method does not exist'];
        }

        $reflection = $class->getMethod($method);
        if (!$reflection->isPublic() || !$reflection->isStatic()) {
            return ['callable' => null, 'issue' => 'declarative factory method must be public and static'];
        }

        return ['callable' => $reflection, 'issue' => null];
    }

    /**
     * @return array{version: string, reference: string|null}
     */
    private function packageIdentity(): array
    {
        $root = InstalledVersions::getRootPackage();
        if ($root['name'] === 'infocyph/intermix') {
            return ['version' => $root['pretty_version'], 'reference' => $root['reference']];
        }
        if (InstalledVersions::isInstalled('infocyph/intermix')) {
            return [
                'version' => InstalledVersions::getPrettyVersion('infocyph/intermix') ?? 'unknown',
                'reference' => InstalledVersions::getReference('infocyph/intermix'),
            ];
        }

        return ['version' => 'unknown', 'reference' => null];
    }

    /**
     * @param array<string, mixed> $metadata
     * @param array<string, array<int, string>> $compiledCodeGroups Compiled expression to service IDs.
     */
    private function renderArtifact(array $metadata, array $compiledCodeGroups): string
    {
        $code = "<?php\n\ndeclare(strict_types=1);\n\nuse Infocyph\\InterMix\\DI\\Container;\n\nreturn [\n";
        $code .= '    \'metadata\' => ' . var_export($metadata, true) . ",\n";
        $code .= "    'resolver' => static function (Container \$c, string \$id): mixed {\n";
        $code .= "        return match (\$id) {\n";
        foreach ($compiledCodeGroups as $expression => $ids) {
            $cases = implode(
                ', ',
                array_map(static fn(string $id): string => var_export($id, true), $ids),
            );
            $code .= "            {$cases} => {$expression},\n";
        }
        $code .= "            default => throw new \\LogicException('Unknown compiled resolver identifier.'),\n";

        return $code . "        };\n    },\n];\n";
    }

    /**
     * @param mixed $definition Registered container definition.
     * @return array<string, mixed>|null
     */
    private function runtimeDefinitionSignature(mixed $definition): ?array
    {
        return match (true) {
            $definition instanceof FactoryDefinition => [
                'kind' => 'declarative-factory',
                'recipe' => $definition->signature(),
            ],
            is_string($definition) => ['kind' => 'string', 'value' => $definition],
            is_array($definition) && isset($definition[0]) && is_string($definition[0]) => [
                'kind' => 'array',
                'class' => $definition[0],
                'method' => isset($definition[1]) && is_string($definition[1]) ? $definition[1] : null,
            ],
            default => null,
        };
    }

    /**
     * @param string $reason Human-readable reason retained in the report.
     * @return array{code: null, signature: null, reason: string}
     */
    private function skipped(string $reason): array
    {
        return ['code' => null, 'signature' => null, 'reason' => $reason];
    }
}
