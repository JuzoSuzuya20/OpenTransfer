<?php

namespace Tests\Unit;

use App\Support\Locales;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Every language the server accepts must be fully translated in the interface: a missing key
 * shows English (or the raw key) in the middle of a translated screen.
 */
class TranslationFilesTest extends TestCase
{
    public static function languages(): array
    {
        return array_map(fn ($code) => [$code], array_combine(Locales::SUPPORTED, Locales::SUPPORTED));
    }

    #[DataProvider('languages')]
    public function test_each_supported_language_has_every_key_of_the_english_file(string $code): void
    {
        $english = self::strings('en');
        $file = self::path($code);

        $this->assertFileExists($file, "No interface translation for '$code'");
        $strings = self::strings($code);

        $this->assertSame([], array_values(array_diff(array_keys($english), array_keys($strings))), "Keys missing in $code.json");
        $this->assertSame([], array_values(array_diff(array_keys($strings), array_keys($english))), "Keys in $code.json that English does not have");
    }

    #[DataProvider('languages')]
    public function test_translations_keep_the_placeholders_of_the_english_text(string $code): void
    {
        if (!is_file(self::path($code))) {
            $this->markTestSkipped("No interface translation for '$code'");
        }

        $english = self::strings('en');
        $wrong = [];
        foreach (self::strings($code) as $key => $text) {
            if (isset($english[$key]) && self::placeholders($text) !== self::placeholders($english[$key])) {
                $wrong[] = $key;
            }
        }

        $this->assertSame([], $wrong, "Translations in $code.json that lose or rename a {placeholder}");
    }

    public function test_no_translation_is_empty(): void
    {
        $empty = [];
        foreach (Locales::SUPPORTED as $code) {
            if (!is_file(self::path($code))) {
                continue;
            }
            foreach (self::strings($code) as $key => $text) {
                if (trim($text) === '') {
                    $empty[] = "$code: $key";
                }
            }
        }

        $this->assertSame([], $empty);
    }

    #[DataProvider('languages')]
    public function test_each_supported_language_has_the_server_texts_of_english(string $code): void
    {
        $base = dirname(__DIR__, 2) . '/lang';
        foreach (glob("$base/en/*.php") as $file) {
            $name = basename($file);
            $this->assertFileExists("$base/$code/$name", "No $name for '$code'");
            $english = require $file;
            $texts = require "$base/$code/$name";
            $this->assertSame(array_keys($english), array_keys($texts), "Keys of lang/$code/$name");
            foreach ($english as $key => $text) {
                preg_match_all('/:([a-z_]+)/', $text, $expected);
                preg_match_all('/:([a-z_]+)/', $texts[$key], $found);
                $this->assertSame($expected[1], $found[1], "Placeholders of $key in lang/$code/$name");
                $this->assertNotSame('', trim($texts[$key]), "Empty $key in lang/$code/$name");
            }
        }
    }

    private static function path(string $code): string
    {
        return dirname(__DIR__, 2) . "/resources/js/i18n/$code.json";
    }

    /** @return array<string, string> the strings of a translation file, keyed by their dotted path */
    private static function strings(string $code): array
    {
        $data = json_decode(file_get_contents(self::path($code)), true, flags: JSON_THROW_ON_ERROR);

        return self::flatten($data);
    }

    private static function flatten(array $data, string $prefix = ''): array
    {
        $out = [];
        foreach ($data as $key => $value) {
            if (is_array($value)) {
                $out += self::flatten($value, $prefix . $key . '.');
            } else {
                $out[$prefix . $key] = (string) $value;
            }
        }

        return $out;
    }

    /** The ICU argument names a text uses ({email}, {count, plural, …}), sorted and without duplicates */
    private static function placeholders(string $text): array
    {
        // The braces that open a plural branch ("one {day}") hold text, not an argument
        $text = preg_replace('/\b(?:zero|one|two|few|many|other|=\d+)\s*\{/u', '[', $text);
        preg_match_all('/\{(\w+)\s*[,}]/u', $text, $matches);
        $names = array_values(array_unique($matches[1]));
        sort($names);

        return $names;
    }
}
