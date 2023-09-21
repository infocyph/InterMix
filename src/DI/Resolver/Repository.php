<?php

namespace AbmmHasan\InterMix\DI\Resolver;

use AbmmHasan\InterMix\Exceptions\ContainerException;

class Repository
{
    public array $functionReference = [];
    public array $classResource = [];
    public ?string $defaultMethod = null;
    public array $closureResource = [];
    public array $resolved = [];
    public array $resolvedResource = [];
    public array $resolvedFunction = [];
    public array $resolvedDefinition = [];
    public bool $enablePropertyAttribute = false;
    public bool $enableMethodAttribute = false;
    public bool $isLocked = false;
    public ?string $cachePath = null;

    /**
     * Check if the container is locked.
     *
     * @return static Returns the instance of the container.
     * @throws ContainerException If the container is locked and unable to set/modify any value.
     */
    public function checkIfLocked(): static
    {
        if ($this->isLocked) {
            throw new ContainerException('Container is locked! Unable to set/modify any value');
        }
        return $this;
    }
}
