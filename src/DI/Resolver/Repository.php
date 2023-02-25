<?php

namespace AbmmHasan\InterMix\DI\Resolver;

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
    public bool $enableAttribute = false;
    public bool $enableProperties = false;
}
