<?php

declare(strict_types=1);

namespace Infocyph\InterMix\DI\Attribute;

use Attribute;

#[Attribute(Attribute::TARGET_METHOD | Attribute::TARGET_PROPERTY | Attribute::TARGET_PARAMETER)]
final class Inject
{
    /** @var array<int|string, mixed> */
    private array $data = [];

    private string|int|null $firstKey = null;

    public function __construct(mixed ...$parameters)
    {
        if ($parameters === []) {
            return;
        }

        $this->firstKey = array_key_first($parameters);
        foreach ($parameters as $key => $value) {
            if (is_int($key)) {
                $this->data[] = $value;
            } else {
                $this->data[$key] = $value;
            }
        }
    }

    public function getMethodArguments(int|string|null $key = null): mixed
    {
        return $key !== null
            ? ($this->data[$key] ?? null)
            : $this->data;
    }

    public function getParameterData(int|string|null $key = null): mixed
    {
        $firstKey = $this->firstKey;

        if (is_int($firstKey) && array_key_exists($firstKey, $this->data)) {
            $firstValue = $this->data[$firstKey];
            $firstKey = is_int($firstValue) || is_string($firstValue) ? $firstValue : null;
            if (is_int($firstKey) || is_string($firstKey)) {
                $this->data[$firstKey] = $this->firstKey;
            }
        }

        $returnable = [
            'type' => $firstKey,
            'data' => is_int($firstKey) || is_string($firstKey)
                ? ($this->data[$firstKey] ?? null)
                : null,
        ];

        return $key !== null ? ($returnable[$key] ?? null) : $returnable;
    }
}
