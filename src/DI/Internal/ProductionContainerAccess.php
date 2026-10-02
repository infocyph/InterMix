<?php

declare(strict_types=1);

namespace Infocyph\InterMix\DI\Internal;

use Closure;
use Infocyph\InterMix\DI\ProductionContainer;

/** @internal */
final class ProductionContainerAccess
{
    public static function attachFallback(
        ProductionContainer $runtime,
        ConfigurationContainer $fallback,
    ): void {
        $attach = Closure::bind(
            function (ConfigurationContainer $fallback): void {
                if ($this->fallback !== $fallback) {
                    $this->fallbackDefinitions = [];
                    $this->runtimeIslands = null;
                }

                $this->captureFallbackDefinitions($fallback);
                $this->installFallbackBridges($fallback);
                $this->fallback = $fallback;
                $this->synchronizeFallbackScopes($fallback);
            },
            $runtime,
            ProductionContainer::class,
        );
        $attach($fallback);
    }
}
