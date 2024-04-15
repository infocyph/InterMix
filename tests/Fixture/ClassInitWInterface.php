<?php

namespace Infocyph\InterMix\Tests\Fixture;

class ClassInitWInterface
{
    private string $dbS;

    /**
     * Resolving constructor
     *
     * @param InterfaceA $interfaceA
     * @param InterfaceB $interfaceB
     * @param InterfaceC $interfaceC
     * @param string $myString
     * @param string $dbS
     */
    public function __construct(
        protected InterfaceA $interfaceA,
        protected InterfaceB $interfaceB,
        protected InterfaceC $interfaceC,
        protected string $myString,
        string $dbS
    ) {
        $this->dbS = $dbS;
    }

    public function getValues()
    {
        return [
            'classA' => $this->interfaceA,
            'classB' => $this->interfaceB,
            'classC' => $this->interfaceC,
            'myString' => $this->myString,
            'dbS' => $this->dbS,
        ];
    }
}