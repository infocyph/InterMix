<?php

declare(strict_types=1);

namespace Infocyph\InterMix\DI\Internal;

use Infocyph\InterMix\DI\ScopeContext;
use Infocyph\InterMix\Exceptions\ContainerException;

/** @internal */
final readonly class CapturedScopeContext implements ScopeContext
{
    public function __construct(
        private object $owner,
        private LogicalScopeState $scope,
    ) {}

    private function __clone(): void {}

    public function __serialize(): array
    {
        throw new ContainerException('Scope contexts are process-local and cannot be serialized.');
    }

    /** @param array<array-key, mixed> $data */
    public function __unserialize(array $data): void
    {
        throw new ContainerException('Scope contexts are process-local and cannot be unserialized.');
    }

    public function unwrap(object $owner): LogicalScopeState
    {
        if ($this->owner !== $owner) {
            throw new ContainerException('Scope context belongs to a different container.');
        }

        return $this->scope;
    }
}
