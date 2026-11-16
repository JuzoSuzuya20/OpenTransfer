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

        return ':root{' . $block($accent['light']) . '}'
            . '@media (prefers-color-scheme: dark){:root:not([data-theme="light"]){' . $dark . '}}'
            . ':root[data-theme="dark"]{' . $dark . '}';
    }

    /** @return array{fill: string, text: string, mark: string} the tones of the dark emails, and their mark */
    public static function email(string $id): array
    {
        $accent = self::all()[self::exists($id) ? $id : self::DEFAULT];

        return $accent['email'] + ['mark_file' => $id === self::DEFAULT || !self::exists($id) ? 'brand-mark.png' : "brand-mark-{$id}.png"];
    }
}
