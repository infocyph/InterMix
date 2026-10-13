<?php

declare(strict_types=1);

namespace Infocyph\InterMix\DI\Attribute;

use Attribute;

#[Attribute(Attribute::TARGET_METHOD | Attribute::TARGET_PROPERTY | Attribute::TARGET_PARAMETER)]
final readonly class Inject
{
    /** @var array<int|string, mixed> */
    private array $parameters;

    /**
     * Store attribute arguments for either parameter injection or method argument overrides.
     */
    public function __construct(mixed ...$parameters)
    {
        $this->parameters = $parameters;
    }

    /**
     * Expose the raw method argument payload, which may require runtime normalization.
     */
    public function getMethodArguments(int|string|null $key = null): mixed
    {
        return $key !== null
            ? ($this->parameters[$key] ?? null)
            : $this->parameters;
    }

    /**
     * Expose the target and optional payload used to resolve an annotated parameter.
     */
    public function getParameterData(int|string|null $key = null): mixed
    {
        $firstKey = array_key_first($this->parameters);
        $target = is_int($firstKey)
            ? ($this->parameters[$firstKey] ?? null)
            : $firstKey;
        $target = is_int($target) || is_string($target) ? $target : null;
        $data = is_string($firstKey) ? ($this->parameters[$firstKey] ?? null) : [];

        $returnable = [
            'type' => $target,
            'data' => $data,
        ];

        return $key !== null ? ($returnable[$key] ?? null) : $returnable;
    }
}
