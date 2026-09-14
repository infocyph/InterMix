<?php

declare(strict_types=1);

namespace Infocyph\InterMix\DI\Internal;

use Infocyph\InterMix\DI\ScopeContext;
use Infocyph\InterMix\Exceptions\ContainerException;

/** @internal */
final class CapturedScopeContext implements ScopeContext
{
    public function __construct(
        private readonly object $owner,
        private readonly LogicalScopeState $scope,
    ) {}

    public function belongsTo(object $owner): bool
    {
        return $this->owner === $owner;
    }

    public function scope(): LogicalScopeState
    {
        return $this->scope;
    }

    /** @return never */
    public function __serialize(): array
    {
        throw new ContainerException('Scope contexts are process-local and cannot be serialized.');
    }

    /** @param array<array-key, mixed> $data */
    public function __unserialize(array $data): void
    {
        throw new ContainerException('Scope contexts are process-local and cannot be unserialized.');
    }

    private function __clone(): void {}
}
