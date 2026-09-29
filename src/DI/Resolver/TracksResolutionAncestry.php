<?php

declare(strict_types=1);

namespace Infocyph\InterMix\DI\Resolver;

use Fiber;
use Infocyph\InterMix\DI\Internal\ExecutionContext;
use Infocyph\InterMix\Exceptions\ContainerException;

/** @internal */
trait TracksResolutionAncestry
{
    /** @var array<string, int|string|array<int|string, true>> */
    private array $resolutionEntries = [];

    private function beginResolutionEntry(string $id, string $cycleMessage): int|string
    {
        $owner = $this->resolutionOwner();
        $active = $this->resolutionEntries[$id] ?? null;
        if ($active === null) {
            $this->resolutionEntries[$id] = $owner;

            return $owner;
        }
        if (is_array($active)) {
            if (isset($active[$owner])) {
                throw new ContainerException($cycleMessage);
            }

            $active[$owner] = true;
            $this->resolutionEntries[$id] = $active;

            return $owner;
        }
        if ($active === $owner) {
            throw new ContainerException($cycleMessage);
        }

        $this->resolutionEntries[$id] = [$active => true, $owner => true];

        return $owner;
    }

    private function endResolutionEntry(string $id, int|string $owner): void
    {
        $active = $this->resolutionEntries[$id] ?? null;
        if (!is_array($active)) {
            if ($active === $owner) {
                unset($this->resolutionEntries[$id]);
            }

            return;
        }

        unset($active[$owner]);
        if ($active === []) {
            unset($this->resolutionEntries[$id]);

            return;
        }

        $this->resolutionEntries[$id] = $active;
    }

    private function resolutionOwner(): int|string
    {
        $fiber = Fiber::getCurrent();

        return $fiber instanceof Fiber
            ? spl_object_id($fiber)
            : (ExecutionContext::id() ?? "\0intermix.root");
    }
}
