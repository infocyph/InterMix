<?php

declare(strict_types=1);

namespace Infocyph\InterMix\Exceptions;

use Throwable;

final class ScopeCleanupException extends ContainerException
{
    private const int RETAINED_FAILURE_LIMIT = 32;

    /** @var list<Throwable> */
    public readonly array $cleanupFailures;

    /**
     * @param list<Throwable> $cleanupFailures
     */
    public function __construct(
        array $cleanupFailures,
        public readonly int $cleanupFailureCount,
        ?Throwable $previous = null,
    ) {
        $this->cleanupFailures = array_slice($cleanupFailures, 0, self::RETAINED_FAILURE_LIMIT);

        parent::__construct(
            sprintf('Scope cleanup failed with %d error(s).', $cleanupFailureCount),
            previous: $previous ?? $cleanupFailures[0] ?? null,
        );
    }
}
