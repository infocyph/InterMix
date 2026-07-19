<?php

declare(strict_types=1);

namespace Infocyph\InterMix\Internal;

use RuntimeException;

/**
 * @internal
 */
final class AtomicFileWriter
{
    public static function write(string $filePath, string $contents): void
    {
        $directory = realpath(dirname($filePath));
        if ($directory === false || !is_dir($directory)) {
            throw new RuntimeException("Output directory does not exist for '$filePath'.");
        }

        $temporaryPath = tempnam($directory, '.intermix-');
        if ($temporaryPath === false) {
            throw new RuntimeException("Unable to create a temporary file for '$filePath'.");
        }

        try {
            $written = file_put_contents($temporaryPath, $contents, LOCK_EX);
            if ($written !== strlen($contents)) {
                throw new RuntimeException("Unable to write complete generated file '$filePath'.");
            }

            if (!rename($temporaryPath, $filePath)) {
                throw new RuntimeException("Unable to atomically replace generated file '$filePath'.");
            }
        } finally {
            if (is_file($temporaryPath)) {
                unlink($temporaryPath);
            }
        }
    }
}
