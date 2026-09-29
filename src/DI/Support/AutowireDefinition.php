<?php

declare(strict_types=1);

namespace Infocyph\InterMix\DI\Support;

/** @internal */
final readonly class AutowireDefinition
{
    /**
     * @param class-string $class
     * @param array<int|string, mixed> $arguments
     * @param array<string, mixed> $properties
     */
    public function __construct(
        public string $class,
        public array $arguments = [],
        public array $properties = [],
    ) {}
}
