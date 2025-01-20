<?php

namespace Infocyph\InterMix\Tests\Fixture;

use Exception;

/**
 * Multi-arg constructor + random property to test single-instance vs make().
 */
class ClassInit
{
    private string $dbS;
    private string $random;

    public function __construct(
        protected ClassA $classA,
        protected string $myString,
        string $dbS
    ) {
        $this->dbS = $dbS;
        // Show random data to test singletons vs. fresh instances
        $this->random = base64_encode(random_bytes(10));
    }

    public function getValues(): array
    {
        return [
            'classA'   => $this->classA,
            'myString' => $this->myString,
            'dbS'      => $this->dbS,
            'random'   => $this->random,
        ];
    }
}
