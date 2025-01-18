<?php

use Infocyph\InterMix\Fence\Common;

class CommonTraitTest
{
    use Common;
}

test('it passes requirements check when constraints are met', function () {
    $mock = new CommonTraitTest;
    $constraints = [
        'extensions' => ['json', 'pcre'], // Assuming these extensions are loaded.
        'classes' => [stdClass::class],
    ];
    $mock::checkRequirements($constraints);

    expect(true)->toBeTrue(); // If no exception is thrown, the test passes.
});

test('it throws an exception for missing extensions', function () {
    $mock = new CommonTraitTest;
    $constraints = ['extensions' => ['nonexistent_extension']];

    $mock::checkRequirements($constraints);
})->throws(Exception::class, 'Requirements not met. Extensions not loaded: nonexistent_extension');

test('it throws an exception for missing classes', function () {
    $mock = new CommonTraitTest;
    $constraints = ['classes' => ['NonExistentClass']];

    $mock::checkRequirements($constraints);
})->throws(Exception::class, 'Requirements not met.  Undeclared Classes: NonExistentClass');
