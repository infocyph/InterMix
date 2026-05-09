<?php

declare(strict_types=1);

namespace Infocyph\InterMix\DI\Attribute;

use Attribute;

/**
 * Attribute for dependency injection of methods, properties, and parameters.
 *
 * This attribute enables automatic dependency injection when applied to:
 * - Methods: Injects dependencies as method arguments
 * - Properties: Injects dependencies directly into class properties
 * - Parameters: Injects specific dependencies for method parameters
 *
 * The attribute accepts flexible parameters to specify injection targets
 * and additional configuration data.
 *
 * Examples:
 * #[Infuse('SomeService')]
 * #[Infuse('logger', 'level' => 'debug')]
 */
#[Attribute(Attribute::TARGET_METHOD | Attribute::TARGET_PROPERTY | Attribute::TARGET_PARAMETER)]
class Infuse
{
    /** @var array<int|string, mixed> */
    private array $data = [];

    private string|int|null $firstKey = null;

    /**
     * Constructs a new instance of the Infuse attribute.
     *
     * @param mixed ...$parameters The parameters declared in the attribute usage.
     *                             For example: #[Infuse('SomeType', ...otherData)]
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
     * Retrieves data used for a method injection scenario.
     *
     * @param int|string|null $key If provided, returns just the sub-value from the array.
     */
    public function getMethodArguments(int|string|null $key = null): mixed
    {
        return $key
            ? ($this->data[$key] ?? null)
            : $this->data;
    }

    /**
     * Retrieves data used for property or parameter injection.
     * The attribute stores the "firstKey" as a "type" and the corresponding value as "data".
     *
     * @param int|string|null $key If provided, returns just the sub-value from the array.
     */
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

        return $key ? ($returnable[$key] ?? null) : $returnable;
    }
}
