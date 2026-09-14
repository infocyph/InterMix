<?php

declare(strict_types=1);

namespace Infocyph\InterMix\DI\Internal;

/** @internal */
final class ScopeState
{
    public int $attachments = 0;

    public bool $closed = false;

    /** @var array<int, string> service slot => constructing carrier */
    public array $constructing = [];

    public readonly bool $hasSeeds;

    /** @var array<int, mixed> */
    public array $resolved = [];

    /** @var array<int, mixed> */
    public array $returned = [];

    /**
     * @param array<int, mixed> $seeds
     * @param array<string, mixed> $rawSeeds
     */
    public function __construct(
        public readonly string $name,
        public readonly ?self $parent = null,
        public readonly array $seeds = [],
        public readonly array $rawSeeds = [],
    ) {
        $this->hasSeeds = $seeds !== [];
    }

    public function contains(string $name): bool
    {
        for ($scope = $this; $scope instanceof self; $scope = $scope->parent) {
            if ($scope->name === $name) {
                return true;
            }
        }

        return false;
    }
}
