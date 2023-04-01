<?php

use AbmmHasan\InterMix\Tests\Fixture\ClassA;
use AbmmHasan\InterMix\Tests\Fixture\PropertyClass;

use function AbmmHasan\InterMix\container;

/** @var PropertyClass $propertyClass */
$propertyClass = container(null, 'propOnly')
    ->setOptions(true, false, true)
    ->registerProperty(PropertyClass::class, [
        'nothing' => 'assigned',
        'staticValue' => 'someValue'
    ])
    ->addDefinitions([
        'db.host' => '127.0.0.1',
        'db.port' => '54321'
    ])
    ->get(PropertyClass::class);

test('Property assigned via register', function () use ($propertyClass) {
    expect($propertyClass->nothing)->toBe('assigned');
});

test('Inject class instance', function () use ($propertyClass) {
    expect($propertyClass->classA)->toBeInstanceOf(ClassA::class);
});

test('Assign from definition', function () use ($propertyClass) {
    expect($propertyClass->something)->toBe('127.0.0.1');
});

test('Function call', function () use ($propertyClass) {
    expect($propertyClass->yesterday)->toBe(strtotime('last monday'));
});

test('Function call (with multiple param)', function () use ($propertyClass) {
    expect($propertyClass->yesterdayFromADate)->toBe(strtotime('last monday', 1678786990));
});

test('Parent Class property', function () use ($propertyClass) {
    expect($propertyClass->getDbPort())->toBe('54321');
});

test('Static property', function () use ($propertyClass) {
    expect($propertyClass->getStaticValue())->toBe('someValue');
});
