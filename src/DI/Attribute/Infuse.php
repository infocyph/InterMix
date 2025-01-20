<?php

declare(strict_types=1);

namespace Infocyph\InterMix\DI\Attribute;

use Attribute;

/**
 * An attribute to indicate injection (property, parameter, method).
 * Example usage: #[Infuse(MyClass::class)] or #[Infuse(key => value)]
 */
#[Attribute(Attribute::TARGET_METHOD | Attribute::TARGET_PROPERTY | Attribute::TARGET_PARAMETER)]
final class Infuse
{
    /**
     * Storage for user-supplied parameters
     */
    private array $data = [];

    /**
     * The first key that was provided, used as a type or other special meaning
     */
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
        // The "firstKey" might be an integer if user wrote #[Infuse(SomeType::class)],
        // in which case we "flip" it to treat that value as the 'type'.
        if (is_int($this->firstKey)) {
            $originalValue = $this->data[$this->firstKey] ?? null;
            $this->firstKey = $originalValue;            // Now store that string (type)
            $this->data[$this->firstKey] = $this->firstKey; // Slightly hacky, you might want a different approach
            unset($this->data[$this->firstKey]); // Avoid duplication
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
