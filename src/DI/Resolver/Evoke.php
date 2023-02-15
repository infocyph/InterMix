<?php

declare(strict_types=1);

namespace AbmmHasan\InterMix\DI\Resolver;

use Attribute;

#[Attribute(Attribute::TARGET_PROPERTY | Attribute::TARGET_METHOD | Attribute::TARGET_PARAMETER)]
final class Evoke
{
    private ?string $name = null;
    private array $parameters = [];

    public function __construct(string|array|null $name = null)
    {
        // #[Evoke('value')] or #[Evoke(name: 'value')]
        if (is_string($name)) {
            $this->name = $name;
        }

        // #[Evoke([...])] on a method
        if (is_array($name)) {
            foreach ($name as $key => $value) {
                if (is_string($value)) {
                    $this->parameters[$key] = $value;
                    continue;
                }
            }
        }
    }

    /**
     * Get Name of the entry to inject
     *
     * @return string|null
     */
    public function getName(): string|null
    {
        return $this->name;
    }

    /**
     * Get parameters, indexed by the parameter number (index) or name
     *
     * @return array
     */
    public function getParameters(): array
    {
        return $this->parameters;
    }
}
