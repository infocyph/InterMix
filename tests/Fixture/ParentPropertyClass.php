<?php

namespace Infocyph\InterMix\Tests\Fixture;

use Infocyph\InterMix\DI\Attribute\Infuse;

class ParentPropertyClass
{
    #[Infuse('db.port')]
    private string $dbPort;

    public function getDbPort()
    {
        return $this->dbPort;
    }
}