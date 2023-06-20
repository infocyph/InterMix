<?php

namespace AbmmHasan\InterMix\Tests\Fixture;

use Exception;

class ClassInit
{
    private string $dbS;
    private string $random;

    /**
     * Resolving constructor
     *
     * @param ClassA $classA
     * @param string $myString
     * @param string $dbS
     * @throws Exception
     */
    public function __construct(
        protected ClassA $classA,
        protected string $myString,
        string $dbS
    ) {
        $this->dbS = $dbS;
        $this->random = base64_encode(random_bytes(50));
    }

    public function getValues()
    {
        return [
            'classA' => $this->classA,
            'myString' => $this->myString,
            'dbS' => $this->dbS,
            'random' => $this->random
        ];
    }
}