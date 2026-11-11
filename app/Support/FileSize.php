<?php

namespace App\Support;

/**
 * A size as the application writes it (niceFileSize in resources/js/utils.js), so that an email
 * and the page it links to show the same figure.
 */
final class FileSize
{
    public static function human(int|float|null $bytes): string
    {
        $bytes = max(0, (float) $bytes);

        return match (true) {
            $bytes < 1024 => sprintf('%d bytes', $bytes),
            $bytes < 1024 ** 2 => sprintf('%.2f KB', $bytes / 1024),
            $bytes < 1024 ** 3 => sprintf('%.2f MB', $bytes / 1024 ** 2),
            default => sprintf('%.2f GB', $bytes / 1024 ** 3),
        };
    }
}
