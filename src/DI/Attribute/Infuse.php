<?php

declare(strict_types=1);

namespace Infocyph\InterMix\DI\Attribute;

use Attribute;

#[Attribute(Attribute::TARGET_METHOD | Attribute::TARGET_PROPERTY | Attribute::TARGET_PARAMETER)]
final class Infuse
{
    private array $data = [];

    private string|int|null $firstKey = null;

    /**
     * Constructs a new instance of the class.
     *
     * @param  mixed  ...$parameters  The parameters to pass to the constructor.
     */
    public function __construct(mixed ...$parameters)
    {
        if (! empty($parameters)) {
            $this->firstKey = array_key_first($parameters);
            foreach ($parameters as $key => $value) {
                is_int($key) ? $this->data[] = $value : $this->data[$key] = $value;
            }
        }
    }

    /**
     * Retrieves non-method data (property/parameter) based on the provided key.
     *
     * @param  int|string|null  $key  The key to retrieve the data for. Defaults to null.
     * @return mixed The retrieved data based on the key, or the entire data if no key is provided.
     */
    public function getNonMethodData(int|string|null $key = null): mixed
    {
        if (is_int($this->firstKey)) {
            [$this->firstKey, $this->data[$this->firstKey]] = [$this->data[$this->firstKey], $this->firstKey];
        }
        $returnable = [
            'type' => $this->firstKey,
            'data' => $this->data[$this->firstKey],
        ];

        return $key ? ($returnable[$key] ?? null) : $returnable;
    }

    /**
     * Retrieves data from the method based on the provided key.
     *
     * @param  int|string|null  $key  The key to retrieve data for. Default is null.
     * @return mixed The retrieved data based on the key, or the entire data if no key is provided.
     */
    public function getMethodData(int|string|null $key = null): mixed
    {
        return $key ? ($this->data[$key] ?? null) : $this->data;
    }
}
