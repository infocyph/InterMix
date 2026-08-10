<?php

declare(strict_types=1);
namespace Infocyph\InterMix\Tests\Fixture;

use Infocyph\InterMix\DI\Attribute\Inject;
use stdClass;

class MixedAttributeExample
{
    #[Inject]
    public ?stdClass $std = null;

    #[Inject('name')]
    public string $name;

    #[ExampleAttr('TEST')]
    public string $custom;
}
