<?php

namespace App\Support;

/**
 * The main colour of the instance (Appearance), among a few tuned for both themes: the same file
 * as the page (resources/js/lib/accents.json). The page arrives in it, the emails take it.
 */
final class Accents
{
    public const DEFAULT = 'indigo';
    public const SETTING = 'accent_color';

    private static ?array $all = null;

    public static function all(): array
    {
        return self::$all ??= json_decode((string) @file_get_contents(base_path('resources/js/lib/accents.json')), true) ?: [];
    }

    public static function exists(mixed $id): bool
    {
        return is_string($id) && array_key_exists($id, self::all());
    }

    // The colour set, or the default one
    public static function chosen(): string
    {
        $id = app(\App\Services\SettingsService::class)->get(self::SETTING);

        return self::exists($id) ? $id : self::DEFAULT;
    }

    // The rules of lib/accents.js (accentCss), empty for the default colour of the styles
    public static function css(string $id): string
    {
        if ($id === self::DEFAULT || !self::exists($id)) {
            return '';
        }
        $block = fn (array $vars) => implode('', array_map(fn ($name, $value) => "--ot-{$name}:{$value};", array_keys($vars), $vars));
        $accent = self::all()[$id];
        $dark = $block($accent['dark']);

        return ':root{' . $block($accent['light']) . "--ot-logo:url(/build/brand/logo-{$id}.png);}"
            . '@media (prefers-color-scheme: dark){:root:not([data-theme="light"]){' . $dark . '}}'
            . ':root[data-theme="dark"]{' . $dark . '}';
    }

    // The logo in this colour, for the tab of the browser (resources/images/brand)
    public static function logoPath(string $id): string
    {
        return resource_path('images/brand/logo-' . (self::exists($id) ? $id : self::DEFAULT) . '.png');
    }

    /**
     * The tones of the emails: the dark ones (the "email" tones of accents.json), and the light ones
     * of their light version, those of the light theme of the application; and the mark in that colour.
     *
     * @return array{fill: string, text: string, mark: string, light: array{fill: string, text: string, mark: string}, mark_file: string}
     */
    public static function email(string $id): array
    {
        $accent = self::all()[self::exists($id) ? $id : self::DEFAULT];

        return $accent['email'] + [
            'light' => ['fill' => $accent['light']['accent-fill'], 'text' => $accent['light']['accent-text'], 'mark' => $accent['light']['accent']],
            'mark_file' => $id === self::DEFAULT || !self::exists($id) ? 'brand-mark.png' : "brand-mark-{$id}.png",
        ];
    }
}
