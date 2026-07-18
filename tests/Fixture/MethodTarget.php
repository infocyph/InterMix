<?php

declare(strict_types=1);
namespace Infocyph\InterMix\Tests\Fixture;
use Infocyph\InterMix\DI\Attribute\Inject;

class MethodTarget
{
    public array $result;

    #[MethodAttr('send')]
    #[ExampleAttr]
    public function send(
        #[Inject('api_key')] string $override,
        #[ExampleAttr('TEST')] string $custom
    ) {
        $this->result = [
            'override' => $override,
            'custom' => $custom,
        ];
    }
}
