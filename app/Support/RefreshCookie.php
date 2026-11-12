<?php

namespace App\Support;

use App\Services\SettingsService;
use Symfony\Component\HttpFoundation\Cookie;

/**
 * The cookie that keeps a session open between visits: never readable by the scripts of the
 * page, and never sent over a plain connection by an instance served over HTTPS. The scheme of
 * the request cannot tell (behind a proxy it is not trusted, see config/trustedproxy.php): the
 * address of the instance does, the one that also builds the SSO callback.
 */
final class RefreshCookie
{
    public const NAME = 'refresh_token';

    public static function make(string $token, int $minutes): Cookie
    {
        return cookie(self::NAME, $token, $minutes, null, null, self::secure(), true);
    }

    public static function forget(): Cookie
    {
        return cookie(self::NAME, '', -2628000, null, null, self::secure(), true);
    }

    private static function secure(): bool
    {
        $address = app(SettingsService::class)->get('application_url') ?: config('app.url');

        return str_starts_with(strtolower(trim((string) $address)), 'https://');
    }
}
