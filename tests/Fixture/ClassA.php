<?php

namespace Infocyph\InterMix\Tests\Fixture;

use Infocyph\InterMix\DI\Attribute\Infuse;

class ClassA implements InterfaceA
{
    /**
     * Showcases method injection with:
     *  1) "ClassB $classB" => typed param
     *  2) "string $parameterA" => with attribute Infuse(...) => if no param is given, use that
     *  3) "string $parameterB" => with #[Infuse('db.host')] => from definition
     *  4) variadic $parameterC => leftover parameters
     */
    #[Infuse(parameterA: 'gethostname')]
    public function resolveIt(
        ClassB $classB,
        string $parameterA,
        #[Infuse('db.host')] string $parameterB,
        ...$parameterC
    ): array {
        return [
            'classB' => $classB,
            'parameterA' => $parameterA,
            'parameterB' => $parameterB,
            'parameterC' => $parameterC
        ];
    }
}
