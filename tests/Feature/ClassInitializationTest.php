<?php

namespace AbmmHasan\InterMix\Tests\Feature;

use AbmmHasan\InterMix\Tests\Fixture\ClassA;
use AbmmHasan\InterMix\Tests\Fixture\ClassB;
use AbmmHasan\InterMix\Tests\Fixture\ClassInit;
use AbmmHasan\InterMix\Tests\Fixture\ClassInitWInterface;
use AbmmHasan\InterMix\Tests\Fixture\InterfaceA;
use AbmmHasan\InterMix\Tests\Fixture\InterfaceB;

use function AbmmHasan\InterMix\container;

/** @var ClassInit $classInit */
$classInit = container(null, 'init_only')
    ->setOptions(true, true)
    ->registerClass(ClassInit::class, [
        'abc',
        'def',
        'dbS' => 'ghi'
    ])
    ->get(ClassInit::class);
$values = $classInit->getValues();

test('Inject by Typehint', function () use ($values) {
    expect($values['classA'])->toBeInstanceOf(ClassA::class);
});

test('Parameter assigned via register (Non-Associative)', function () use ($values) {
    expect($values['myString'])
        ->toBe('abc');
});

test('Parameter assigned via register (Associative)', function () use ($values) {
    expect($values['dbS'])
        ->toBe('ghi');
});

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
        InterfaceA::class => ClassA::class
    ])
    ->get(ClassInitWInterface::class);




