<?php

namespace AbmmHasan\InterMix\Tests\Fixture;

class ClassMethod
{

    /**
     * Resolving method
     * 1. 'ClassModel' class will be resolved into $classModel
     * 2. If any parameter with the name 'classModel' is delivered, the value will be sent to
     *    constructor of 'ClassModel' (parameter to Class binding)
     * 3. If 'parameterA' don't have any parameter supply in form of Key => Value (associative)
     *    it will be resolved by calling time() function
     * 4. If 'parameterB' don't have any supply in form of value (non-associative) / Key => Value
     *    (associative) the 'db.host' will be resolved from definition (same steps as of property)
     * 5. Any leftover supply parameter(s) will be resolved in variadic $parameterC
     * 6. If variadic is not present and supply parameters have values left, it will be ignored
     *
     * @param ClassModel $classModel
     * @param string $parameterA
     * @param string $parameterB
     * @param ...$parameterC
     * @return void
     */
    #[Infuse(parameterA: 'time')]
    public function resolveIt(
        ClassModel $classModel,
        string $parameterA,
        #[Infuse('db.host')] string $parameterB,
        ...$parameterC
    ) {
        // doing some operations here
    }
}