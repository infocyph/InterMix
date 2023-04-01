<?php

namespace AbmmHasan\InterMix\Tests\Fixture;

class ClassInit
{
    private string $dbS;

    /**
     * Resolving constructor
     *
     * @param ClassA $classA
     * @param string $myString
     * @param string $dbS
     */
    public function __construct(
        protected ClassA $classA,
        protected string $myString,
        string $dbS
    ) {
        $this->dbS = $dbS;
    }

    public function getValues()
    {
        return [
            'classA' => $this->classA,
            'myString' => $this->myString,
            'dbS' => $this->dbS,
        ];
    }
}