<?php

declare(strict_types=1);

use Rector\Config\RectorConfig;
use Rector\Php81\Rector\Property\ReadOnlyPropertyRector;

return static function (RectorConfig $rectorConfig): void {
    $rectorConfig->paths([
        __DIR__.'/src',
    ]);
    $rectorConfig->sets([
        constant("Rector\Set\ValueObject\LevelSetList::UP_TO_PHP_84"),
    ]);
};
