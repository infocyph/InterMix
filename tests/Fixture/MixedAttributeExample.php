<?php
namespace Infocyph\InterMix\Tests\Fixture;

use Infocyph\InterMix\DI\Attribute\Autowire;
use Infocyph\InterMix\DI\Attribute\Infuse;
use stdClass;

class MixedAttributeExample
{
    #[Autowire]
    public ?stdClass $std = null;

    #[Infuse('name')]
    public string $name;

    #[ExampleAttr('TEST')]
    public string $custom;
}
