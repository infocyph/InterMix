<?php

declare(strict_types=1);

namespace Infocyph\InterMix\DI\Support;

use Infocyph\InterMix\DI\Container;
use ReflectionException;
use ReflectionNamedType;

final class CompiledResolverGenerator
{
    /**
     * @return array<string, callable(Container): mixed>
     * @throws ReflectionException
     */
    public function generate(Container $container, string $filePath): array
    {
        $repo = $container->getRepository();
        $definitions = $repo->getFunctionReference();
        $compiledCodeEntries = [];

        foreach ($definitions as $id => $definition) {
            $compiledEntry = $this->compileEntry($definition);
            if ($compiledEntry === null) {
                continue;
            }

            $compiledCodeEntries[] = var_export($id, true) . ' => ' . $compiledEntry;
        }

        $code = "<?php\n\ndeclare(strict_types=1);\n\nuse Infocyph\\InterMix\\DI\\Container;\n\nreturn [\n";
        if ($compiledCodeEntries !== []) {
            $code .= '    ' . implode(",\n    ", $compiledCodeEntries) . ",\n";
        }
        $code .= "];\n";

        file_put_contents($filePath, $code);

        /** @var array<string, callable(Container): mixed> $result */
        $result = require $filePath;

        return $result;
    }

    /**
     * @param array<int|string, mixed> $definition
     */
    private function compileArrayDefinition(array $definition): ?string
    {
        if (!isset($definition[0]) || !is_string($definition[0]) || !class_exists($definition[0])) {
            return null;
        }

        $class = '\\' . ltrim($definition[0], '\\');
        $method = isset($definition[1]) && is_string($definition[1])
            ? var_export($definition[1], true)
            : 'false';

        return "static fn(Container \$c): mixed => \$c->make({$class}::class, {$method})";
    }

    private function compileClassDefinition(string $definition): ?string
    {
        $class = ReflectionResource::getClassReflection($definition);
        if (!$class->isInstantiable()) {
            return null;
        }

        $constructor = $class->getConstructor();
        if ($constructor === null || $constructor->getParameters() === []) {
            $fqcn = '\\' . ltrim($definition, '\\');

            return "static fn(Container \$c): mixed => new {$fqcn}()";
        }

        $arguments = $this->compileConstructorArguments($constructor->getParameters());
        if ($arguments === null) {
            return null;
        }

        $fqcn = '\\' . ltrim($definition, '\\');
        $args = implode(', ', $arguments);

        return "static fn(Container \$c): mixed => new {$fqcn}({$args})";
    }

    /**
     * @param array<int, \ReflectionParameter> $parameters
     * @return array<int, string>|null
     */
    private function compileConstructorArguments(array $parameters): ?array
    {
        $arguments = [];
        foreach ($parameters as $parameter) {
            if ($parameter->isVariadic()) {
                return null;
            }

            $type = $parameter->getType();
            if ($type instanceof ReflectionNamedType && !$type->isBuiltin()) {
                $arguments[] = '$c->get(\\' . ltrim($type->getName(), '\\') . '::class)';

                continue;
            }

            if ($parameter->isDefaultValueAvailable()) {
                $arguments[] = var_export($parameter->getDefaultValue(), true);

                continue;
            }

            if ($parameter->allowsNull()) {
                $arguments[] = 'null';

                continue;
            }

            return null;
        }

        return $arguments;
    }

    private function compileEntry(mixed $definition): ?string
    {
        if (is_array($definition)) {
            return $this->compileArrayDefinition($definition);
        }

        if (!is_string($definition) || !class_exists($definition)) {
            return null;
        }

        return $this->compileClassDefinition($definition);
    }
}
