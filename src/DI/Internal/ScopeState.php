<?php

declare(strict_types=1);

namespace Infocyph\InterMix\DI\Internal;

/** @internal */
final class ScopeState
{
    public readonly bool $hasSeeds;

    public int $attachments = 0;

    public bool $closed = false;

    /** @var array<int, string> service slot => constructing carrier */
    public array $constructing = [];

    public bool $draining = false;

    /** @var array<int, mixed> */
    public array $resolved = [];

    public bool $retained = false;

    /** @var array<int, mixed> */
    public array $returned = [];

    /**
     * Create a native scope frame with slot-indexed seeds and identifier seeds for fallback
     * bridging.
     *
     * @param array<int, mixed> $seeds
     * @param array<string, mixed> $rawSeeds
     */
    public function __construct(
        public readonly string $name,
        public ?self $parent = null,
        public array $seeds = [],
        public array $rawSeeds = [],
    ) {
        $this->hasSeeds = $seeds !== [];
    }

    /**
     * Invalidate retained handles and release references to seeds, resolved values and ancestors.
     */
    public function close(): void
    {
        $this->closed = true;
        $this->constructing = [];
        if (!$this->retained) {
            return;
        }

        $this->draining = false;
        $this->parent = null;
        $this->rawSeeds = [];
        $this->resolved = [];
        $this->returned = [];
        $this->seeds = [];
    }

    /**
     * Check this frame and its ancestors to prevent duplicate active scope names.
     */
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
