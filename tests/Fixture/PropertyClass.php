<?php

declare(strict_types=1);

namespace Infocyph\InterMix\Tests\Fixture;

use Infocyph\InterMix\DI\Attribute\Inject;

class PropertyClass extends ParentPropertyClass
{
    public static string $staticValue;

    private string $nothing;

    #[Inject]
    private ClassA $classA;

    #[Inject('db.host')]
    private string $something;

    #[Inject(strtotime: 'last monday')]
    private int $yesterday;

    #[Inject(strtotime: ['last monday', 1678786990])]
    private int $yesterdayFromADate;

    public function __get(string $key): mixed
    {
        return $this->{$key};
    }

    public function getStaticValue(): ?string
    {
        return self::$staticValue;
    }
}
