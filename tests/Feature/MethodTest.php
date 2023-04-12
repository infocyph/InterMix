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
    function () use ($classMethodValues, $classMethodAttribute) {
        expect($classMethodValues['parameterC'])->toBe(['def', 'jkl'])
            ->and($classMethodAttribute['parameterC'])->toBeArray()->toBeEmpty();
    }
);

test(
    'Parameter assigned via method attribute (doc-block attached)',
    function () use ($classMethodAttribute) {
        expect($classMethodAttribute['parameterA'])->toBe(gethostname());
    }
);

test(
    'Parameter assigned via parameter attribute (used definition)',
    function () use ($classMethodAttribute) {
        expect($classMethodAttribute['parameterB'])->toBe('127.0.0.1');
    }
);
