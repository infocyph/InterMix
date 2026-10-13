<?php

declare(strict_types=1);

namespace Infocyph\InterMix\DI\Internal;

/** @internal */
final class LogicalScopeState
{
    public int $attachments = 0;

    public bool $closed = false;

    /** @var array<string, string> service id => constructing carrier */
    public array $constructing = [];

    public bool $draining = false;

    public bool $retained = false;

    /**
     * Create a shareable scope frame containing execution-owned seeds and resolved entries.
     *
     * @param array<string, mixed> $seeds
     * @param array<string, mixed> $resolvedScoped
     */
    public function __construct(
        public readonly string $name,
        public ?self $parent = null,
        public array $seeds = [],
        public array $resolvedScoped = [],
    ) {}

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
        $this->resolvedScoped = [];
        $this->seeds = [];
    }

    /**
     * Check this frame and its ancestors to prevent duplicate active scope names.
     */
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
