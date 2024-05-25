<?php

namespace Infocyph\InterMix\Tests\Feature;

use Infocyph\InterMix\Tests\Fixture\{ClassA, ClassB, ClassC};
use Infocyph\InterMix\Tests\Fixture\{ClassInit, ClassInitWInterface, InterfaceA, InterfaceB, InterfaceC};

use function Infocyph\InterMix\container;

/** @var ClassInit $classInit */
$classInit = container(null, 'init_only')
    ->setOptions(true, true)
    ->registerClass(ClassInit::class, [
        'abc',
        'def',
        'dbS' => 'ghi'
    ])
    ->get(ClassInit::class);
$classInitValues = $classInit->getValues();

/** @var ClassInitWInterface $classInterfaceTest */
$classInterfaceTest = container(null, 'init_with_interface')
    ->setOptions(true, true)
    ->registerClass(ClassInitWInterface::class, [
        InterfaceB::class => ClassB::class,
        'abc',
        'def',
        'ghi'
    ])
    ->addDefinitions([
        InterfaceA::class => ClassA::class,
        InterfaceC::class => new ClassC()
    ])
    ->get(ClassInitWInterface::class);
$classInterfaceValues = $classInterfaceTest->getValues();

test('Inject via Typehint', function () use ($classInitValues) {
    expect($classInitValues['classA'])->toBeInstanceOf(ClassA::class);
});

test(
    'Parameter assigned via register (Non-Associative)',
    function () use ($classInitValues, $classInterfaceValues) {
        expect($classInitValues['myString'])->toBe('abc')
            ->and($classInterfaceValues['myString'])->toBe('abc')
            ->and($classInterfaceValues['dbS'])->toBe('def');
    }
);

test(
    'Parameter assigned via register (Associative)',
    function () use ($classInitValues, $classInterfaceValues) {
        expect($classInitValues['dbS'])->toBe('ghi');
    }
);

test('Interface Name to Class Name (via register)', function () use ($classInterfaceValues) {
    expect($classInterfaceValues['classB'])->toBeInstanceOf(ClassB::class);
});

test('Interface Name to Class Name (via addDefinition)', function () use ($classInterfaceValues) {
    expect($classInterfaceValues['classA'])->toBeInstanceOf(ClassA::class);
});

test('Interface Name to Class Object (via addDefinition)', function () use ($classInterfaceValues) {
    expect($classInterfaceValues['classC'])->toBeInstanceOf(ClassC::class);
});