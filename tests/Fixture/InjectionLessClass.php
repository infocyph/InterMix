<?php

namespace Infocyph\InterMix\Tests\Fixture;

class InjectionLessClass
{
    public string $internalProperty;
    public static string $staticProperty;

    public function __construct(private string $id)
    {
    }

    public function ilc(string $param)
    {
        return [
            'internalProperty' => $this->internalProperty ?? 'xyz',
            'staticProperty' => self::$staticProperty ?? 'xyz',
            'constructor' => $this->id,
            'method' => $param
        ];
    }
}
