<?php

namespace AbmmHasan\OOF\DI\Resolver;

class Repository
{
    public array $functionReference = [];
    public array $classResource = [];
    public ?string $defaultMethod = null;
    public array $closureResource = [];
    public array $resolved = [];
    public array $resolvedResource = [];
    public array $resolvedDefinition = [];
}
