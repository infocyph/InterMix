<?php

declare(strict_types=1);

namespace AbmmHasan\InterMix\DI\Attribute;

use Attribute;

#[Attribute(Attribute::TARGET_METHOD)]
final class Infuse
{
    private array $data = [];

    public function __construct(...$parameters)
    {
        if (!empty($parameters)) {
            foreach ($parameters as $type => $value) {
                if (is_int($type)) {
                    $this->data[] = $value;
                    continue;
                }
                $this->data[$type] = $value;
            }
        }
    }

    /**
     * Get resource of the entry to inject
     *
     * @param int|string|null $key
     * @return array|string|null
     */
    public function getData(int|string $key = null): mixed
    {
        return $key ? ($this->data[$key] ?? null) : $this->data;
    }
}
