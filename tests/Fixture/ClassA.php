<?php

declare(strict_types=1);

namespace Infocyph\InterMix\Tests\Fixture;

use Infocyph\InterMix\DI\Attribute\Inject;

class ClassA implements InterfaceA
{
    /**
     * Showcases method injection with:
     *  1) "ClassB $classB" => typed param
     *  2) "string $parameterA" => with attribute Inject(...) => if no param is given, use that
     *  3) "string $parameterB" => with #[Inject('db.host')] => from definition
     *  4) variadic $parameterC => leftover parameters
     */
    #[Inject(parameterA: 'gethostname')]
    public function resolveIt(
        ClassB $classB,
        string $parameterA,
        #[Inject('db.host')] string $parameterB,
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
