<?php

declare(strict_types=1);

namespace Infocyph\InterMix\DI\Build;

use Infocyph\InterMix\DI\Support\LifetimeEnum;
use Infocyph\InterMix\Exceptions\ContainerException;
use Infocyph\InterMix\Internal\AtomicFileWriter;
use Infocyph\InterMix\Internal\ReflectionResource;
use Psr\Container\ContainerInterface;
use ReflectionClass;
use ReflectionIntersectionType;
use ReflectionNamedType;
use ReflectionParameter;
use ReflectionUnionType;

/** @internal */
final class StaticRuntimeGenerator
{
    /**
     * @return array{
     *   runtime: ContainerInterface,
     *   compiled: list<string>,
     *   skipped: array<string, string>
     * }
     */
    public function generate(DefinitionGraph $graph, string $filePath): array
    {
        [$plans, $skipped] = $this->buildPlans($graph);
        $plans = $this->pruneUnavailableDependencies($plans, $skipped);
        $plans = $this->pruneCycles($plans, $skipped);
        ksort($plans, SORT_STRING);

        $slots = [];
        foreach (array_keys($plans) as $slot => $id) {
            $slots[$id] = $slot;
        }

        $source = $this->renderRuntime($plans, $slots);
        AtomicFileWriter::write(
            $filePath,
            $source,
            function (string $temporaryPath): void {
                $this->load($temporaryPath);
            },
        );

        return [
            'runtime' => $this->load($filePath),
            'compiled' => array_keys($plans),
            'skipped' => $skipped,
        ];
    }

    public function load(string $filePath): ContainerInterface
    {
        if (!is_file($filePath) || !is_readable($filePath)) {
            throw new ContainerException("Static runtime artifact is not readable: '$filePath'.");
        }

        $runtime = require $filePath;
        if (!$runtime instanceof ContainerInterface) {
            throw new ContainerException('Static runtime artifact must return a PSR-11 container.');
        }

        return $runtime;
    }

    /**
     * @return array{
     *   array<string, array{class: class-string, lifetime: LifetimeEnum, arguments: list<array{kind: 'service', id: string}|array{kind: 'value', code: string}>, dependencies: list<string>}>,
     *   array<string, string>
     * }
     */
    private function buildPlans(DefinitionGraph $graph): array
    {
        $plans = [];
        $skipped = [];
        $definitions = $graph->definitions();
        ksort($definitions, SORT_STRING);

        foreach ($definitions as $id => $definition) {
            $plan = $this->planDefinition($graph, $id, $definition);
            if (is_string($plan)) {
                $skipped[$id] = $plan;

                continue;
            }
            $plans[$id] = $plan;
        }

        return [$plans, $skipped];
    }

    /**
     * @param ReflectionClass<object> $class
     * @return array{arguments: list<array{kind: 'service', id: string}|array{kind: 'value', code: string}>, dependencies: list<string>}|string
     */
    private function constructorPlan(DefinitionGraph $graph, ReflectionClass $class): array|string
    {
        $constructor = $class->getConstructor();
        if ($constructor === null) {
            return ['arguments' => [], 'dependencies' => []];
        }

        $arguments = [];
        $dependencies = [];
        $seenDependencies = [];
        foreach ($constructor->getParameters() as $parameter) {
            $argument = $this->parameterPlan($graph, $class, $parameter);
            if (is_string($argument)) {
                return $argument;
            }
            if ($argument['kind'] === 'service') {
                if (isset($seenDependencies[$argument['id']])) {
                    return "constructor dependency '{$argument['id']}' occurs more than once";
                }
                $seenDependencies[$argument['id']] = true;
                $dependencies[] = $argument['id'];
            }
            $arguments[] = $argument;
        }

        return ['arguments' => $arguments, 'dependencies' => $dependencies];
    }

    /**
     * @param array<string, array{dependencies: list<string>}> $plans
     * @param array<string, true> $remaining
     */
    private function hasRemainingDependency(array $plans, array $remaining, string $id): bool
    {
        foreach ($plans[$id]['dependencies'] as $dependency) {
            if (isset($remaining[$dependency])) {
                return true;
            }
        }

        return false;
    }

    /** @param ReflectionClass<object> $class */
    private function implicitMethod(DefinitionGraph $graph, ReflectionClass $class): ?string
    {
        $constant = $class->hasConstant('CALL_ON') ? 'CALL_ON' : 'callOn';
        $callOn = $class->hasConstant($constant) ? $class->getConstant($constant) : null;
        if (is_string($callOn) && $callOn !== '' && $class->hasMethod($callOn)) {
            return $callOn;
        }

        $default = $graph->defaultMethod();
        if (is_string($default) && $default !== '' && $class->hasMethod($default)) {
            return $default;
        }

        return $class->hasMethod('__invoke') ? '__invoke' : null;
    }

    private function isExportable(mixed $value): bool
    {
        if ($value === null || is_scalar($value)) {
            return true;
        }
        if (!is_array($value)) {
            return false;
        }

        return array_all($value, fn(mixed $item): bool => $this->isExportable($item));
    }

    /** @param ReflectionClass<object> $class */
    private function normalizedDependencyType(ReflectionClass $class, string $type): ?string
    {
        if ($type === 'self') {
            return $class->getName();
        }
        if ($type === 'parent') {
            $parent = $class->getParentClass();

            return $parent instanceof ReflectionClass ? $parent->getName() : null;
        }

        return $type === 'static' ? null : $type;
    }

    /**
     * @param ReflectionClass<object> $class
     * @return array{kind: 'service', id: string}|array{kind: 'value', code: string}|string
     */
    private function parameterPlan(
        DefinitionGraph $graph,
        ReflectionClass $class,
        ReflectionParameter $parameter,
    ): array|string {
        if ($parameter->isVariadic()) {
            return "constructor parameter '{$parameter->getName()}' is variadic";
        }
        if ($parameter->getAttributes() !== []) {
            return "constructor parameter '{$parameter->getName()}' has attributes";
        }

        $type = $parameter->getType();
        if ($type instanceof ReflectionUnionType || $type instanceof ReflectionIntersectionType) {
            return "constructor parameter '{$parameter->getName()}' has a union or intersection type";
        }
        if ($type instanceof ReflectionNamedType && !$type->isBuiltin()) {
            $dependency = $this->normalizedDependencyType($class, $type->getName());
            if ($dependency === null) {
                return "constructor parameter '{$parameter->getName()}' has an unsupported relative type";
            }
            if ($graph->hasDefinition($parameter->getName())) {
                return "constructor parameter '{$parameter->getName()}' is shadowed by a named definition";
            }
            if ($graph->hasContextualBinding($class->getName(), $dependency)) {
                return "constructor dependency '$dependency' has a contextual binding";
            }

            return ['kind' => 'service', 'id' => $dependency];
        }
        if ($parameter->isDefaultValueAvailable() && $this->isExportable($parameter->getDefaultValue())) {
            return ['kind' => 'value', 'code' => var_export($parameter->getDefaultValue(), true)];
        }
        if ($parameter->allowsNull()) {
            return ['kind' => 'value', 'code' => 'null'];
        }

        return "constructor parameter '{$parameter->getName()}' cannot be represented statically";
    }

    /**
     * @return array{class: class-string, lifetime: LifetimeEnum, arguments: list<array{kind: 'service', id: string}|array{kind: 'value', code: string}>, dependencies: list<string>}|string
     */
    private function planDefinition(DefinitionGraph $graph, string $id, mixed $definition): array|string
    {
        if (!is_string($definition) || !class_exists($definition)) {
            return 'definition is not a concrete class-string';
        }

        $meta = $graph->definitionMetaFor($id);
        if ($meta['lifetime'] === LifetimeEnum::Scoped) {
            return 'scoped lifetime is not implemented by the static runtime candidate';
        }

        $class = ReflectionResource::getClassReflection($definition);
        if (!$class->isInstantiable()) {
            return 'class definition is not instantiable';
        }
        if ($graph->classResourcesFor($class->getName()) !== []) {
            return 'class has registered runtime resources';
        }
        if ($graph->propertyAttributesEnabled()) {
            return 'property attributes are enabled for the graph';
        }

        $method = $this->implicitMethod($graph, $class);
        if ($method !== null) {
            return "class invokes implicit method '$method'";
        }

        $constructor = $this->constructorPlan($graph, $class);
        if (is_string($constructor)) {
            return $constructor;
        }

        return [
            'class' => $class->getName(),
            'lifetime' => $meta['lifetime'],
            'arguments' => $constructor['arguments'],
            'dependencies' => $constructor['dependencies'],
        ];
    }

    /**
     * @param array<string, array{class: class-string, lifetime: LifetimeEnum, arguments: list<array{kind: 'service', id: string}|array{kind: 'value', code: string}>, dependencies: list<string>}> $plans
     * @param array<string, string> $skipped
     * @return array<string, array{class: class-string, lifetime: LifetimeEnum, arguments: list<array{kind: 'service', id: string}|array{kind: 'value', code: string}>, dependencies: list<string>}>
     */
    private function pruneCycles(array $plans, array &$skipped): array
    {
        $remaining = array_fill_keys(array_keys($plans), true);
        do {
            $changed = false;
            foreach (array_keys($remaining) as $id) {
                if ($this->hasRemainingDependency($plans, $remaining, $id)) {
                    continue;
                }
                unset($remaining[$id]);
                $changed = true;
            }
        } while ($changed);

        foreach (array_keys($remaining) as $id) {
            $skipped[$id] = 'static dependency graph contains or depends on a cycle';
            unset($plans[$id]);
        }

        return $plans;
    }

    /**
     * @param array<string, array{class: class-string, lifetime: LifetimeEnum, arguments: list<array{kind: 'service', id: string}|array{kind: 'value', code: string}>, dependencies: list<string>}> $plans
     * @param array<string, string> $skipped
     * @return array<string, array{class: class-string, lifetime: LifetimeEnum, arguments: list<array{kind: 'service', id: string}|array{kind: 'value', code: string}>, dependencies: list<string>}>
     */
    private function pruneUnavailableDependencies(array $plans, array &$skipped): array
    {
        do {
            $changed = false;
            foreach ($plans as $id => $plan) {
                foreach ($plan['dependencies'] as $dependency) {
                    if (isset($plans[$dependency])) {
                        continue;
                    }
                    $skipped[$id] = "dependency '$dependency' is not statically compiled";
                    unset($plans[$id]);
                    $changed = true;

                    break;
                }
            }
        } while ($changed);

        return $plans;
    }

    /**
     * @param array{class: class-string, lifetime: LifetimeEnum, arguments: list<array{kind: 'service', id: string}|array{kind: 'value', code: string}>, dependencies: list<string>} $plan
     * @param array<string, int> $slots
     */
    private function renderMethod(int $slot, array $plan, array $slots): string
    {
        $arguments = [];
        foreach ($plan['arguments'] as $argument) {
            $arguments[] = $argument['kind'] === 'service'
                ? '$this->s' . $slots[$argument['id']] . '()'
                : $argument['code'];
        }

        $construction = 'new \\' . ltrim($plan['class'], '\\') . '(' . implode(', ', $arguments) . ')';
        $return = $plan['lifetime'] === LifetimeEnum::Singleton
            ? '$this->v' . $slot . ' ??= ' . $construction
            : $construction;

        return "    private function s{$slot}(): mixed\n"
            . "    {\n"
            . "        return {$return};\n"
            . "    }\n\n";
    }

    /**
     * @param array<string, array{class: class-string, lifetime: LifetimeEnum, arguments: list<array{kind: 'service', id: string}|array{kind: 'value', code: string}>, dependencies: list<string>}> $plans
     * @param array<string, int> $slots
     */
    private function renderRuntime(array $plans, array $slots): string
    {
        $source = "<?php\n\ndeclare(strict_types=1);\n\n";
        $source .= "use Infocyph\\InterMix\\Exceptions\\NotFoundException;\n";
        $source .= "use Psr\\Container\\ContainerInterface;\n\n";
        $source .= "return new class implements ContainerInterface\n{\n";

        foreach ($plans as $id => $plan) {
            if ($plan['lifetime'] !== LifetimeEnum::Singleton) {
                continue;
            }
            $slot = $slots[$id];
            $class = '\\' . ltrim($plan['class'], '\\');
            $source .= "    private ?{$class} \$v{$slot} = null;\n";
        }
        if ($plans !== []) {
            $source .= "\n";
        }

        $source .= "    public function get(string \$id): mixed\n    {\n        return match (\$id) {\n";
        foreach ($plans as $id => $_plan) {
            $source .= '            ' . var_export($id, true) . ' => $this->s' . $slots[$id] . "(),\n";
        }
        $source .= "            default => throw new NotFoundException('Unknown static runtime identifier.'),\n";
        $source .= "        };\n    }\n\n";

        $source .= "    public function has(string \$id): bool\n    {\n        return match (\$id) {\n";
        if ($plans !== []) {
            $ids = implode(', ', array_map(static fn(string $id): string => var_export($id, true), array_keys($plans)));
            $source .= "            {$ids} => true,\n";
        }
        $source .= "            default => false,\n        };\n    }\n\n";

        foreach ($plans as $id => $plan) {
            $source .= $this->renderMethod($slots[$id], $plan, $slots);
        }

        return rtrim($source) . "\n};\n";
    }
}
