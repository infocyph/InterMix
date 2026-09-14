<?php

declare(strict_types=1);

namespace Infocyph\InterMix\DI\Internal;

/** @internal */
final class LogicalScopeState
{
    public int $attachments = 0;

    public bool $closed = false;

    /** @var array<string, mixed> */
    public array $resolvedScoped;

    /**
     * @param array<string, mixed> $seeds
     * @param array<string, mixed> $resolvedScoped
     */
    public function __construct(
        public readonly string $name,
        public readonly ?self $parent = null,
        public readonly array $seeds = [],
        array $resolvedScoped = [],
    ) {
        $this->resolvedScoped = $resolvedScoped;
    }

    public function contains(string $scope): bool
    {
        for ($current = $this; $current instanceof self; $current = $current->parent) {
            if ($current->name === $scope) {
                return true;
            }
        }

        return false;
    }
}
