<?php

namespace AbmmHasan\InterMix\Tests\Feature;

use AbmmHasan\InterMix\Tests\Fixture\ClassA;
use AbmmHasan\InterMix\Tests\Fixture\ClassB;

use function AbmmHasan\InterMix\container;

/** @var ClassA $classMethodValues */
$classMethodValues = container(null, 'method')
    ->registerMethod(ClassA::class, 'resolveIt', [
        'abc',
        'def',
        'parameterB' => 'ghi',
        'jkl'
    ])
    ->getReturn(ClassA::class);

/** @var ClassA $classMethodAttribute */
$classMethodAttribute = container(null, 'method_attribute')
    ->setOptions(true, true)
    ->addDefinitions([
        'db.host' => '127.0.0.1'
    ])
    ->registerMethod(ClassA::class, 'resolveIt')
    ->getReturn(ClassA::class);

test('Inject using method attribute', function () use ($classMethodAttribute) {
    expect($classMethodAttribute['parameterA'])->toBe(gethostname());
});

//dd($classMethodAttribute);

test('Inject via Typehint', function () use ($classMethodValues, $classMethodAttribute) {
    expect($classMethodValues['classB'])->toBeInstanceOf(ClassB::class)
        ->and($classMethodAttribute['classB'])->toBeInstanceOf(ClassB::class);
});

test(
    'Parameter assigned via register (Non-Associative)',
    function () use ($classMethodValues) {
        expect($classMethodValues['parameterA'])->toBe('abc');
    }
);

test(
    'Parameter assigned via register (Associative)',
    function () use ($classMethodValues) {
        expect($classMethodValues['parameterB'])->toBe('ghi');
    }
);

test(
    'Variadic parameter',
    function () use ($classMethodValues) {
        expect($classMethodValues['parameterC'])->toBe(['def', 'jkl']);
    }
);


///** @var ClassInitWInterface $classInterfaceTest */
//$classInterfaceTest = container(null, 'init_with_interface')
//    ->setOptions(true, true)
//    ->registerClass(ClassInitWInterface::class, [
//        InterfaceB::class => ClassB::class,
//        'abc',
//        'def',
//        'ghi'
//    ])
//    ->addDefinitions([
//        InterfaceA::class => ClassA::class,
//        InterfaceC::class => new ClassC()
//    ])
//    ->get(ClassInitWInterface::class);
//$classInterfaceValues = $classInterfaceTest->getValues();