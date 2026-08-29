<?php

declare(strict_types=1);

namespace Infocyph\InterMix\DI\Build;

use Infocyph\InterMix\DI\Container;
use Infocyph\InterMix\DI\ProductionContainer;
use Infocyph\InterMix\Exceptions\ContainerException;
use Infocyph\InterMix\Internal\AtomicFileWriter;

/** @internal */
final class StaticRuntimeGenerator
{
    /**
     * @return array{
     *   runtime: ProductionContainer,
     *   compiled: list<string>,
     *   skipped: array<string, string>
     * }
     */
    public function generate(
        DefinitionGraph $graph,
        string $filePath,
        ?Container $fallback = null,
    ): array {
        $planned = (new StaticRuntimePlanner())->plan($graph);
        $plans = $planned['plans'];
        $slots = [];
        foreach (array_keys($plans) as $slot => $id) {
            $slots[$id] = $slot;
        }

        $source = (new StaticRuntimeRenderer())->render($graph, $plans, $slots);
        AtomicFileWriter::write(
            $filePath,
            $source,
            function (string $temporaryPath): void {
                $this->load($temporaryPath);
            },
        );

        return [
            'runtime' => $this->load($filePath, $fallback),
            'compiled' => array_keys($plans),
            'skipped' => $planned['skipped'],
        ];
    }

    public function load(string $filePath, ?Container $fallback = null): ProductionContainer
    {
        if (!is_file($filePath) || !is_readable($filePath)) {
            throw new ContainerException("Static runtime artifact is not readable: '$filePath'.");
        }

        $runtime = require $filePath;
        if (!$runtime instanceof ProductionContainer) {
            throw new ContainerException('Static runtime artifact must return a production container.');
        }
        if ($fallback instanceof Container) {
            $runtime->attachFallback($fallback);
        }

        return $runtime;
    }
}
