<?php

namespace App\Support;

use App\Models\User;

/**
 * A new password ends the sessions opened before it (users.sessions_valid_from): a session left
 * on another computer, or a cookie someone took, stopped working only at the end of its week.
 * A refresh token is dated by its sign-in (auth_time), an access token by its issue (iat).
 */
final class SessionRevocation
{
    public static function end(User $user): void
    {
        $user->forceFill(['sessions_valid_from' => now()])->save();
    }

    public static function revoked(User $user, mixed $since): bool
    {
        $from = $user->sessions_valid_from?->getTimestamp();
        if ($from === null) {
            return false;
        }

        return !is_numeric($since) || (int) $since < $from;
    }

    public static function refusal()
    {
        return response()->json([
            'status' => 'error',
            'code' => 'session_revoked',
            'message' => 'The password of this account changed: please sign in again',
        ], 401);
    }
}
