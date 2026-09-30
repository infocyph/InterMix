<?php

declare(strict_types=1);

namespace Infocyph\InterMix\DI\Support;

use Infocyph\InterMix\DI\Container;
use Infocyph\InterMix\DI\Internal\BoundedValueInspector;
use InvalidArgumentException;
use ReflectionClass;

/**
 * A compilation-safe construction recipe with no captured runtime state.
 *
 * Arguments are positional and must be scalar, null, recursively exportable
 * arrays, or explicit {@see ServiceReference} instances.
 */
final readonly class FactoryDefinition
{
    /** @var array<int, scalar|array<array-key, mixed>|ServiceReference|null> */
    public readonly array $arguments;

    /**
     * @param class-string $class
     * @param string|null $method Public static factory name, or null for construction.
     * @param array<int, scalar|array<array-key, mixed>|ServiceReference|null> $arguments
     */
    private function __construct(
        public string $class,
        public ?string $method,
        array $arguments,
    ) {
        self::assertTarget($class, $method);
        $this->arguments = self::snapshotArguments($arguments);
    }

    /**
     * @param class-string $class
     * @param array<int, scalar|array<array-key, mixed>|ServiceReference|null> $arguments
     */
    public static function construct(string $class, array $arguments = []): self
    {
        return new self($class, null, $arguments);
    }

    /**
     * @param class-string $class
     * @param array<int, scalar|array<array-key, mixed>|ServiceReference|null> $arguments
     */
    public static function staticFactory(string $class, string $method, array $arguments = []): self
    {
        if ($method === '') {
            throw new InvalidArgumentException('A static factory method cannot be empty.');
        }

        return new self($class, $method, $arguments);
    }

    public function resolve(Container $container): mixed
    {
        $arguments = [];
        foreach ($this->arguments as $argument) {
            $arguments[] = $argument instanceof ServiceReference
                ? $container->get($argument->id)
                : $argument;
        }

        $class = $this->class;
        if ($this->method !== null) {
            $method = $this->method;

            return $class::$method(...$arguments);
        }

        return new $class(...$arguments);
    }

    /** @return array{class: class-string, method: string|null, arguments: array<int, mixed>} */
    public function signature(): array
    {
        $arguments = [];
        foreach ($this->arguments as $argument) {
            $arguments[] = $argument instanceof ServiceReference
                ? ['service' => $argument->id]
                : ['value' => $argument];
        }

        return [
            'class' => $this->class,
            'method' => $this->method,
            'arguments' => $arguments,
        ];
    }

    /** @param class-string $class */
    private static function assertTarget(string $class, ?string $method): void
    {
        if (!class_exists($class)) {
            throw new InvalidArgumentException("Factory class '{$class}' does not exist.");
        }

        $reflection = new ReflectionClass($class);
        if ($method === null) {
            if (!$reflection->isInstantiable()) {
                throw new InvalidArgumentException("Factory class '{$class}' is not instantiable.");
            }

            return;
        }

        if (!$reflection->hasMethod($method)) {
            throw new InvalidArgumentException("Factory method '{$class}::{$method}' does not exist.");
        }

        $factory = $reflection->getMethod($method);
        if (!$factory->isPublic() || !$factory->isStatic()) {
            throw new InvalidArgumentException('Declarative factory methods must be public and static.');
        }
    }

    private static function isExportable(mixed $value): bool
    {
        return BoundedValueInspector::isScalarNullArray($value);
    }

    /**
     * @param array<int, scalar|array<array-key, mixed>|ServiceReference|null> $arguments
     * @return array<int, scalar|array<array-key, mixed>|ServiceReference|null>
     */
    private static function snapshotArguments(array $arguments): array
    {
        if (!array_is_list($arguments)) {
            throw new InvalidArgumentException('Declarative factory arguments must be a positional list.');
        }

        $snapshot = [];
        foreach ($arguments as $argument) {
            if (!$argument instanceof ServiceReference && !self::isExportable($argument)) {
                throw new InvalidArgumentException(
                    'Declarative factory arguments must be service references or exportable values.',
                );
            }

            $snapshot[] = $argument instanceof ServiceReference
                ? $argument
                : self::snapshotValue($argument);
        }

        return $snapshot;
    }

    /** @return scalar|array<array-key, mixed>|null */
    private static function snapshotValue(mixed $value): mixed
    {
        if (!is_array($value)) {
            return $value;
        }

        $snapshot = [];
        foreach ($value as $key => $item) {
            $snapshot[$key] = self::snapshotValue($item);
        }

        return $snapshot;
    }
}
