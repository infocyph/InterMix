<?php

declare(strict_types=1);

namespace AbmmHasan\InterMix\DI\Attribute;

use Attribute;

#[Attribute(Attribute::TARGET_METHOD | Attribute::TARGET_PROPERTY | Attribute::TARGET_PARAMETER)]
final class Infuse
{
    private array $data = [];
    private array $dataByKeyType = [];

    private string|int $firstKey;

    public function __construct(...$parameters)
    {
        if (!empty($parameters)) {
            $this->firstKey = array_key_first($parameters);
            foreach ($parameters as $type => $value) {
                if (is_int($type)) {
                    $this->data[] = $value;
                    continue;
                }
                $this->data[$type] = $value;
                $this->dataByKeyType[$type] = $value;
            }
        }
    }

    /**
     * Get resource of the entry to inject (on property/parameter)
     *
     * @param int|string|null $key
     * @return array|mixed|null
     */
    public function getNonMethodData(int|string $key = null): mixed
    {
        if (is_int($this->firstKey)) {
            [$this->firstKey, $this->data[$this->firstKey]] = [$this->data[$this->firstKey], $this->firstKey];
        }
        $returnable = [
            'type' => $this->firstKey,
            'data' => $this->data[$this->firstKey]
        ];
        return $key ? ($returnable[$key] ?? null) : $returnable;
    }

    /**
     * Get resource of the entry to inject (on method)
     *
     * @param int|string|null $key
     * @return array|string|null
     */
    public function getMethodData(int|string $key = null): mixed
    {
        return $key ? ($this->data[$key] ?? null) : $this->dataByKeyType;
    }
}
