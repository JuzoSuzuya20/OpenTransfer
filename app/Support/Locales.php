<?php

namespace App\Support;

use App\Models\User;
use App\Services\SettingsService;

/**
 * The languages of the application, for its screens and its emails. An account gets its emails in
 * its own language; people without an account, in the default language of the instance.
 */
final class Locales
{
    public const SUPPORTED = ['en', 'fr', 'de', 'es', 'it', 'nl', 'pt'];

    public static function isSupported(?string $locale): bool
    {
        return in_array($locale, self::SUPPORTED, true);
    }

    public static function default(): string
    {
        $locale = app(SettingsService::class)->get('default_language');

        return self::isSupported($locale) ? $locale : 'en';
    }

    public static function forUser(?User $user): string
    {
        return $user && self::isSupported($user->language) ? $user->language : self::default();
    }
}
