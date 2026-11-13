<?php

namespace App\Support;

/**
 * A session lasts seven days from the sign-in that opened it (the auth_time claim of its refresh
 * token): renewing the access token along the way never pushes that end further, so a cookie
 * copied by someone else stops working within the week.
 */
final class SessionLifetime
{
    public const DAYS = 7;

    public const CLAIM = 'auth_time';

    // The sign-in a renewal keeps. Sessions opened before this rule carry none: their week
    // starts at their next renewal.
    public static function signedInAt(mixed $claim): int
    {
        $now = now()->getTimestamp();

        return is_numeric($claim) && (int) $claim <= $now ? (int) $claim : $now;
    }

    // Whole minutes left before the session ends (the unit of JWT lifetimes and cookies)
    public static function minutesLeft(int $signedInAt): int
    {
        return max(0, intdiv($signedInAt + self::DAYS * 86400 - now()->getTimestamp(), 60));
    }
}
