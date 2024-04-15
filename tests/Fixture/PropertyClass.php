<?php

namespace Infocyph\InterMix\Tests\Fixture;

use Infocyph\InterMix\DI\Attribute\Infuse;

class PropertyClass extends ParentPropertyClass
{
    private static string $staticValue;

    private string $nothing;

    #[Infuse]
    private ClassA $classA;

    #[Infuse('db.host')]
    private string $something;

    #[Infuse(strtotime: 'last monday')]
    private int $yesterday;
    #[Infuse(strtotime: ['last monday', 1678786990])]
    private int $yesterdayFromADate;

    public function __get(string $key)
    {
        return $this->{$key};
    }

    public function getStaticValue()
    {
        return self::$staticValue;
    }
}