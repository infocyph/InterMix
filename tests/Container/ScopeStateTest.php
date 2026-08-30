<?php

declare(strict_types=1);

use Infocyph\InterMix\DI\Internal\ScopeState;

it('precomputes whether a scope has compiled seeds', function () {
    $empty = new ScopeState('empty');
    $seeded = new ScopeState('seeded', seeds: [3 => new stdClass()]);

    expect($empty->hasSeeds)->toBeFalse()
        ->and($seeded->hasSeeds)->toBeTrue();
});

it('does not treat raw fallback seeds as compiled slot seeds', function () {
    $scope = new ScopeState('raw', rawSeeds: ['service' => new stdClass()]);

    expect($scope->hasSeeds)->toBeFalse();
});
