<?php

use Infocyph\InterMix\DI\Container;
use Infocyph\InterMix\DI\Support\PreloadGenerator;

it('generates a preload list', function () {
    $c = Container::instance('intermix');
    $file = sys_get_temp_dir() . '/_preload.php';
    (new PreloadGenerator())->generate($c, $file);

    expect(is_file($file))->toBeTrue()
        ->and(file_get_contents($file))->toContain('require_once');
});
