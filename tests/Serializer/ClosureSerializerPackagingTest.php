<?php

declare(strict_types=1);

it('keeps Opis Closure serialization optional for production installs', function () {
    $composer = json_decode(
        file_get_contents(dirname(__DIR__, 2) . '/composer.json'),
        true,
        flags: JSON_THROW_ON_ERROR,
    );

    expect($composer['require'])->not->toHaveKey('opis/closure')
        ->and($composer['require-dev'])->toHaveKey('opis/closure')
        ->and($composer['suggest'])->toHaveKey('opis/closure');
});
