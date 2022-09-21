<?php

namespace AbmmHasan\OOF\DI;

class Asset
{
    public array $functionReference = [];
    public array $classResource = [];
    public bool $allowPrivateMethodAccess = false;
    public string $resolveParameters = 'resolveAssociativeParameters';
    public ?string $defaultMethod = null;
    public array $resolvedResource = [];
    public bool $forceSingleton = false;
    public array $closureResource = [];
}
