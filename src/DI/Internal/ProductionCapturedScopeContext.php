<?php

declare(strict_types=1);

namespace Infocyph\InterMix\DI\Internal;

use Infocyph\InterMix\DI\ScopeContext;
use Infocyph\InterMix\Exceptions\ContainerException;

/** @internal */
final readonly class ProductionCapturedScopeContext implements ScopeContext
{
    public function __construct(
        private object $owner,
        private ScopeState $scope,
        private ?ScopeContext $fallbackContext = null,
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

    /** @return array{ScopeState, ScopeContext|null} */
    public function unwrap(object $owner): array
    {
        if ($this->owner !== $owner) {
            throw new ContainerException('Scope context belongs to a different container.');
        }

        return [$this->scope, $this->fallbackContext];
    }
}
