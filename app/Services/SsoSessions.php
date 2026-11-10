<?php

namespace App\Services;

use App\Models\AuthProvider;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * Which identity provider opened a session, and the ID token it gave, kept on the server under a
 * random key carried by the refresh token: the ID token is too large for a cookie, and the browser
 * never needs it. The logout uses it once to close the session at the provider too.
 */
class SsoSessions
{
    private const TTL = 60 * 60 * 25;

    public static function remember(?AuthProvider $provider, ?string $idToken): ?string
    {
        if (!$provider) {
            return null;
        }

        $sid = Str::random(40);
        Cache::put(self::key($sid), ['provider_id' => $provider->id, 'id_token' => $idToken], self::TTL);

        return $sid;
    }

    public static function logoutUrl(?string $refreshToken): ?string
    {
        if (!$refreshToken) {
            return null;
        }

        $sid = rescue(fn () => Auth::setToken(urldecode($refreshToken))->getPayload()->get('sid'), null, false);
        $session = is_string($sid) ? Cache::pull(self::key($sid)) : null;
        $provider = $session ? AuthProvider::where('id', $session['provider_id'])->where('enabled', true)->first() : null;
        if (!$provider) {
            return null;
        }

        $class = 'App\\AuthProviders\\' . $provider->provider_class . 'AuthProvider';
        if (!class_exists($class)) {
            return null;
        }

        return (new $class($provider))->logoutUrl($session['id_token'] ?? null, self::returnAddress());
    }

    // Where the provider sends the browser back: the home page, to declare in the provider
    public static function returnAddress(): string
    {
        $base = app(SettingsService::class)->get('application_url') ?: config('app.url');

        return rtrim((string) $base, '/') . '/';
    }

    private static function key(string $sid): string
    {
        return "sso_session:$sid";
    }
}
