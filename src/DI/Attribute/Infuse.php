<?php

declare(strict_types=1);

namespace Infocyph\InterMix\DI\Attribute;

use Attribute;

#[Attribute(Attribute::TARGET_METHOD | Attribute::TARGET_PROPERTY | Attribute::TARGET_PARAMETER)]
class Infuse
{
    private array $data = [];
    private string|int|null $firstKey = null;

    /**
     * Constructs a new instance of the Infuse attribute.
     *
     * @param  mixed  ...$parameters  The parameters declared in the attribute usage.
     *                                For example: #[Infuse('SomeType', ...otherData)]
     */
    public function __construct(mixed ...$parameters)
    {
        if (!empty($parameters)) {
            $this->firstKey = array_key_first($parameters);
            foreach ($parameters as $key => $value) {
                if (is_int($key)) {
                    $this->data[] = $value;
                } else {
                    $this->data[$key] = $value;
                }
            }
        }
    }

    /**
     * Retrieves data used for property or parameter injection.
     * The attribute stores the "firstKey" as a "type" and the corresponding value as "data".
     *
     * @param  int|string|null  $key  If provided, returns just the sub-value from the array.
     * @return mixed
     */
    public function getParameterData(int|string|null $key = null): mixed
    {
        if (is_int($this->firstKey)) {
            [$this->firstKey, $this->data[$this->firstKey]] = [$this->data[$this->firstKey], $this->firstKey];
        }

        $returnable = [
            'type' => $this->firstKey,
            'data' => $this->data[$this->firstKey] ?? null,
        ];

        return $key ? ($returnable[$key] ?? null) : $returnable;
    }

    /**
     * Retrieves data used for a method injection scenario.
     *
     * @param  int|string|null  $key  If provided, returns just the sub-value from the array.
     * @return mixed
     */
    public function getMethodArguments(int|string|null $key = null): mixed
    {
        return $key
            ? ($this->data[$key] ?? null)
            : $this->data;
    }
}
