<?php

namespace Infocyph\InterMix\Tests\Fixture;

/**
 * Constructor injection for multiple interfaces + scalar strings.
 */
class ClassInitWInterface
{
    private string $dbS;

    public function __construct(
        protected InterfaceA $interfaceA,
        protected InterfaceB $interfaceB,
        protected InterfaceC $interfaceC,
        protected string $myString,
        string $dbS
    ) {
        $this->dbS = $dbS;
    }

    public function getValues(): array
    {
        return [
            'classA'   => $this->interfaceA,
            'classB'   => $this->interfaceB,
            'classC'   => $this->interfaceC,
            'myString' => $this->myString,
            'dbS'      => $this->dbS,
        ];
    }
}
