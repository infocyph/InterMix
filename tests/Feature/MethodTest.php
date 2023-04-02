<?php

namespace AbmmHasan\InterMix\Tests\Feature;

use AbmmHasan\InterMix\Tests\Fixture\ClassA;

use function AbmmHasan\InterMix\container;

/** @var ClassA $classMethodValues */
$classMethodValues = container(null, 'method')
    ->registerMethod(ClassA::class, 'resolveIt', [
        'abc',
        'def',
        'dbS' => 'ghi'
    ])
    ->getReturn(ClassA::class);

/** @var ClassA $classMethodValues */
$classMethodValues = container(null, 'method_attribute')
    ->setOptions(true, true)
    ->registerMethod(ClassA::class, 'resolveIt', [
        'abc',
        'def',
        'dbS' => 'ghi'
    ])
    ->getReturn(ClassA::class);



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