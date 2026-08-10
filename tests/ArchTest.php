<?php

declare(strict_types=1);

test('No debugging statements', function () {
    expect(['dd', 'dump', 'ray', 'die', 'd', 'eval', 'sleep', 'print_r', 'var_dump'])->each->not()->toBeUsed();
});

test('No echo statements', function () {
    expect(['echo', 'print'])->each->not()->toBeUsed();
});

test('feature modules respect InterMix dependency boundaries', function () {
    $sourceRoot = dirname(__DIR__) . '/src';
    $rules = [
        'Fence' => ['DI\\', 'Serializer\\', 'Remix\\'],
        'Serializer' => ['DI\\', 'Fence\\', 'Remix\\'],
        'Remix' => ['DI\\', 'Fence\\', 'Serializer\\'],
        'Internal' => ['DI\\', 'Fence\\', 'Remix\\', 'Serializer\\'],
    ];

    foreach ($rules as $module => $forbiddenModules) {
        $files = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator("$sourceRoot/$module"),
        );

        foreach ($files as $file) {
            if (!$file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }

            $source = file_get_contents($file->getPathname());
            expect($source)->toBeString();

            foreach ($forbiddenModules as $forbiddenModule) {
                expect($source)->not->toContain("Infocyph\\InterMix\\$forbiddenModule");
            }
        }
    }
});
