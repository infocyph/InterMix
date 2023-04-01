<?php

namespace AbmmHasan\InterMix\Tests\Fixture;

use AbmmHasan\InterMix\DI\Attribute\Infuse;

class ParentPropertyClass
{
    #[Infuse('db.port')]
    private string $dbPort;

    public function getDbPort()
    {
        return $this->dbPort;
    }
}