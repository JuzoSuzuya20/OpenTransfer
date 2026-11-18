<?php

namespace Tests\Unit;

use App\Support\BlueMindDarkTheme;
use PHPUnit\Framework\TestCase;

/**
 * The model of BlueMind in its dark theme against what the screenshots of the user measured
 * (2026-11-17): what was sent, what BlueMind showed.
 */
class BlueMindDarkThemeTest extends TestCase
{
    private const MEASURED = [
        // greys, of the dark email and of the light version
        '#ffffff' => '#1f1f1f', '#f6f6f6' => '#252525', '#161616' => '#ededed', '#1e1e1e' => '#e3e3e3',
        // accents: the mark and the label of indigo, the deep blue of the light version, the mark of emerald
        '#6a6af4' => '#7271fc', '#8585f6' => '#5e63cf', '#000091' => '#f0b9ff', '#10b981' => '#007342',
    ];

    private function assertNear(string $expected, string $actual, int $steps, string $message = ''): void
    {
        foreach ([1, 3, 5] as $at) {
            $this->assertLessThanOrEqual($steps, abs(hexdec(substr($expected, $at, 2)) - hexdec(substr($actual, $at, 2))), "{$message} {$expected} / {$actual}");
        }
    }

    public function test_it_shows_what_bluemind_showed(): void
    {
        foreach (self::MEASURED as $sent => $shown) {
            $this->assertNear($shown, BlueMindDarkTheme::shows($sent), 2, $sent);
        }
    }

    public function test_the_colour_to_send_comes_back_as_the_dark_design(): void
    {
        foreach (BlueMindDarkTheme::DESIGN as $role => $colour) {
            $this->assertNear($colour, BlueMindDarkTheme::shows(BlueMindDarkTheme::toShow($colour)), 1, $role);
        }
    }

    public function test_each_main_colour_comes_back_near_its_dark_tones(): void
    {
        $accents = json_decode(file_get_contents(__DIR__ . '/../../resources/js/lib/accents.json'), true);
        foreach ($accents as $name => $accent) {
            foreach ($accent['email'] as $tone => $colour) {
                $shown = BlueMindDarkTheme::lab(BlueMindDarkTheme::shows(BlueMindDarkTheme::toShow($colour)));
                $wanted = BlueMindDarkTheme::lab($colour);
                $gap = sqrt(array_sum(array_map(fn ($x, $y) => ($x - $y) ** 2, $shown, $wanted)));
                // Out of reach in sRGB, emerald and orange come back softer: still their colour
                $this->assertLessThan(13, $gap, "{$name} {$tone}");
            }
        }
    }

    public function test_the_light_version_is_light(): void
    {
        $palette = BlueMindDarkTheme::palette(['fill' => '#6262ee', 'text' => '#8585f6', 'mark' => '#6a6af4']);

        $this->assertSame('#ffffff', $palette['page']);
        // The text keeps its grey, the accent its colour: one name each
        $this->assertSame(BlueMindDarkTheme::toShow('#cecece'), $palette['text']);
        $this->assertSame(BlueMindDarkTheme::toShow('#8585f6'), $palette['accent']);
        $this->assertGreaterThan(BlueMindDarkTheme::lab($palette['card'])[0], BlueMindDarkTheme::lab($palette['page'])[0]);
        $this->assertLessThan(20, BlueMindDarkTheme::lab($palette['head'])[0]);
    }
}
