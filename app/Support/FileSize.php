<?php

namespace App\Support;

/**
 * A size as the screens write it (resources/js/lib/fileSize.js): the units of the reader's language
 * (Mo in French), its decimal separator, as many decimals as the size needs (two under 10, one
 * under 100, none above), a no-break space before the unit. So an email and the page it links to
 * show the same figure.
 */
final class FileSize
{
    private const UNITS = [
        'en' => ['bytes', 'KB', 'MB', 'GB', 'TB'],
        'fr' => ['octets', 'Ko', 'Mo', 'Go', 'To'],
        'de' => ['Byte', 'KB', 'MB', 'GB', 'TB'],
        'es' => ['bytes', 'KB', 'MB', 'GB', 'TB'],
        'it' => ['byte', 'KB', 'MB', 'GB', 'TB'],
        'nl' => ['bytes', 'KB', 'MB', 'GB', 'TB'],
        'pt' => ['bytes', 'KB', 'MB', 'GB', 'TB'],
    ];

    public static function human(int|float|null $bytes, ?string $locale = null): string
    {
        $language = isset(self::UNITS[$locale]) ? $locale : 'en';
        $units = self::UNITS[$language];
        $value = max(0, (float) $bytes);
        $unit = 0;
        while ($value >= 1024 && $unit < count($units) - 1) {
            $value /= 1024;
            $unit++;
        }
        $digits = $unit === 0 ? 0 : ($value < 10 ? 2 : ($value < 100 ? 1 : 0));
        // At most those decimals, none written for nothing (2,50 is 2,5)
        $number = rtrim(rtrim(number_format(round($value, $digits), $digits, '.', ''), '0'), '.');
        if ($digits === 0) {
            $number = number_format(round($value), 0, '.', '');
        }
        if ($language !== 'en') {
            $number = str_replace('.', ',', $number);
        }

        return $number . "\u{00A0}" . $units[$unit];
    }
}
