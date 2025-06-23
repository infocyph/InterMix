<?php
namespace Infocyph\InterMix\Tests\Fixture;
use Infocyph\InterMix\DI\Attribute\Inject;

class MethodTarget
{
    public array $result;

    #[ExampleAttr]
    public function send(
        #[Inject('api_key')] string $override,
        #[ExampleAttr('TEST')] string $custom
    ) {
        $this->result = compact('override', 'custom');
    }
}
