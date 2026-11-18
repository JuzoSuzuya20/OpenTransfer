<?php

namespace App\Support;

/**
 * What BlueMind, in its dark theme, does to every colour of an email (fitted on screenshots of a
 * dark and of a light email, 2026-11-17: eight colours, each within one step): it turns over its
 * lightness, CIELAB L* on a line (L* ← 100.17 − 0.883 × L*), keeps its a* and b*, and clips the
 * result into sRGB. It does so after taking the dark version of the email (prefers-color-scheme).
 *
 * The emails give it a light version, made of the colours it turns into the dark design
 * (resources/views/emails/layout.twig): toShow() finds each, the colour to send for BlueMind to
 * show a given one, or the nearest it can show when the colour is out of reach.
 */
final class BlueMindDarkTheme
{
    private const LIGHTNESS_AT_ZERO = 100.17;

    private const LIGHTNESS_SLOPE = 0.883;

    /**
     * The dark design as BlueMind can show it: it shows nothing darker than #1f1f1f, so the grounds
     * and the lines stand 9 steps above those of the application (#161616, #1e1e1e, #242424…),
     * which keeps the card lighter than the page.
     */
    public const DESIGN = [
        'page' => '#1f1f1f', 'card' => '#272727', 'field' => '#2d2d2d',
        'line' => '#3e3e3e', 'line_field' => '#434343', 'line_soft' => '#333333',
        'head' => '#ffffff', 'text' => '#cecece', 'muted' => '#929292', 'on_fill' => '#ffffff',
    ];

    /** @var array<string, string> */
    private static array $found = [];

    /** The colour BlueMind shows for this one */
    public static function shows(string $hex): string
    {
        [$l, $a, $b] = self::lab($hex);

        return self::hex(self::fromLab(self::LIGHTNESS_AT_ZERO - self::LIGHTNESS_SLOPE * $l, $a, $b));
    }

    /** The colour to send for BlueMind to show this one, or the nearest it can show */
    public static function toShow(string $hex): string
    {
        $hex = strtolower($hex);
        if (isset(self::$found[$hex])) {
            return self::$found[$hex];
        }
        $target = self::lab($hex);
        [$l, $a, $b] = $target;
        // The line solved for the lightness, then a search in sRGB when clipping moved the colour
        $rgb = array_map(fn ($c) => (int) round($c), self::fromLab((self::LIGHTNESS_AT_ZERO - $l) / self::LIGHTNESS_SLOPE, $a, $b));
        $gap = fn (array $rgb) => self::distance(self::lab(self::shows(self::hex($rgb))), $target);
        $best = $gap($rgb);
        foreach ([32, 16, 8, 4, 2, 1] as $step) {
            do {
                $moved = false;
                foreach ([0, 1, 2] as $channel) {
                    foreach ([-$step, $step] as $delta) {
                        $next = $rgb;
                        $next[$channel] = max(0, min(255, $next[$channel] + $delta));
                        if (($distance = $gap($next)) < $best - 1e-9) {
                            [$rgb, $best, $moved] = [$next, $distance, true];
                        }
                    }
                }
            } while ($moved);
        }

        return self::$found[$hex] = self::hex($rgb);
    }

    /**
     * The light version of the emails: the colour to send for each role of the dark design, the
     * main colour of the instance included: its button (fill), its text (accent) and its mark.
     *
     * @param  array{fill: string, text: string, mark: string}  $accent
     * @return array<string, string>
     */
    public static function palette(array $accent): array
    {
        return array_map(self::toShow(...), self::DESIGN)
            + ['fill' => self::toShow($accent['fill']), 'accent' => self::toShow($accent['text']), 'mark' => self::toShow($accent['mark'])];
    }

    /** @return array{float, float, float} */
    public static function lab(string $hex): array
    {
        [$r, $g, $b] = array_map(fn ($c) => self::linear(hexdec($c) / 255), str_split(ltrim($hex, '#'), 2));
        $x = (0.4124564 * $r + 0.3575761 * $g + 0.1804375 * $b) / 0.95047;
        $y = 0.2126729 * $r + 0.7151522 * $g + 0.0721750 * $b;
        $z = (0.0193339 * $r + 0.1191920 * $g + 0.9503041 * $b) / 1.08883;
        [$fx, $fy, $fz] = array_map(fn ($t) => $t > (6 / 29) ** 3 ? $t ** (1 / 3) : $t / (3 * (6 / 29) ** 2) + 4 / 29, [$x, $y, $z]);

        return [116 * $fy - 16, 500 * ($fx - $fy), 200 * ($fy - $fz)];
    }

    /** @return array{float, float, float} sRGB from 0 to 255, clipped */
    private static function fromLab(float $l, float $a, float $b): array
    {
        $fy = ($l + 16) / 116;
        [$x, $y, $z] = array_map(fn ($t) => $t > 6 / 29 ? $t ** 3 : 3 * (6 / 29) ** 2 * ($t - 4 / 29), [$fy + $a / 500, $fy, $fy - $b / 200]);
        [$x, $z] = [$x * 0.95047, $z * 1.08883];
        $linear = [
            3.2404542 * $x - 1.5371385 * $y - 0.4985314 * $z,
            -0.9692660 * $x + 1.8760108 * $y + 0.0415560 * $z,
            0.0556434 * $x - 0.2040259 * $y + 1.0572252 * $z,
        ];

        return array_map(fn ($c) => 255 * self::gamma(max(0.0, min(1.0, $c))), $linear);
    }

    private static function linear(float $c): float
    {
        return $c <= 0.04045 ? $c / 12.92 : (($c + 0.055) / 1.055) ** 2.4;
    }

    private static function gamma(float $c): float
    {
        return $c <= 0.0031308 ? 12.92 * $c : 1.055 * $c ** (1 / 2.4) - 0.055;
    }

    private static function hex(array $rgb): string
    {
        return '#' . implode('', array_map(fn ($c) => sprintf('%02x', max(0, min(255, (int) round($c)))), $rgb));
    }

    private static function distance(array $one, array $other): float
    {
        return sqrt(($one[0] - $other[0]) ** 2 + ($one[1] - $other[1]) ** 2 + ($one[2] - $other[2]) ** 2);
    }
}
