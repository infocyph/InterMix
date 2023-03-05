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
    public bool $enableProperties = false;
    public bool $enableMethodAttribute = false;
    public bool $isLocked = false;

    /**
     * Lock check
     *
     * @return Repository
     * @throws ContainerException
     */
    public function checkIfLocked(): static
    {
        if ($this->isLocked) {
            throw new ContainerException('Container is locked! Unable to set/modify any value');
        }
        return $this;
    }
}
