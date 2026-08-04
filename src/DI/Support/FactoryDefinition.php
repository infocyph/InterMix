<?php

declare(strict_types=1);

namespace Infocyph\InterMix\DI\Support;

use Infocyph\InterMix\DI\Container;
use InvalidArgumentException;

/**
 * A compilation-safe construction recipe with no captured runtime state.
 *
 * Arguments are positional and must be scalar, null, recursively exportable
 * arrays, or explicit {@see ServiceReference} instances.
 */
final readonly class FactoryDefinition
{
    /**
     * @param class-string $class
     * @param string|null $method Public static factory name, or null for construction.
     * @param array<int, scalar|array<array-key, mixed>|ServiceReference|null> $arguments
     */
    private function __construct(
        public string $class,
        public ?string $method,
        public array $arguments,
    ) {
        if (!array_is_list($this->arguments)) {
            throw new InvalidArgumentException('Declarative factory arguments must be a positional list.');
        }

        foreach ($this->arguments as $argument) {
            if (!$argument instanceof ServiceReference && !self::isExportable($argument)) {
                throw new InvalidArgumentException(
                    'Declarative factory arguments must be service references or exportable values.',
                );
            }
        }
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

    /**
     * @return array{class: class-string, method: string|null, arguments: array<int, mixed>}
     */
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

    private static function isExportable(mixed $value): bool
    {
        if ($value === null || is_scalar($value)) {
            return true;
        }
        if (!is_array($value)) {
            return false;
        }
        foreach ($value as $item) {
            if (!self::isExportable($item)) {
                return false;
            }
        }

        return true;
    }
}
