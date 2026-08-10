<?php

declare(strict_types=1);

namespace Infocyph\InterMix\Tests\Fixture;

use Infocyph\InterMix\DI\Attribute\Inject;

class ParentPropertyClass
{
    #[Inject('db.port')]
    private string $dbPort;

    public function getDbPort(): string
    {
        return $this->dbPort;
    }
}
