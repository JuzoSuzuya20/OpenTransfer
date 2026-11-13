<?php

namespace App\Http\Controllers;

use App\Support\RefreshCookie;
use App\Support\SessionLifetime;
use Illuminate\Support\Facades\Auth;

/**
 * Issues the access token and the refresh cookie of a user.
 */
trait IssuesTokens
{
    /**
     * @param string $amr how the session was opened ("pwd", "passkey" or "sso"), kept across
     *                    renewals: the second factor policy only covers passwords
     * @param int|null $signedInAt when the session was opened, kept across renewals too
     */
    protected function respondWithToken($user, string $amr = 'pwd', ?string $sid = null, ?int $signedInAt = null)
    {
        $token = Auth::login($user);

        if (!$token) {
            return response()->json([
                'status' => 'error',
                'message' => 'Unauthorized'
            ], 401);
        }

        // Seven days from the sign-in, renewals included (SessionLifetime)
        $signedInAt ??= now()->getTimestamp();
        $minutes = SessionLifetime::minutesLeft($signedInAt);
        $claims = array_filter(['amr' => $amr, 'sid' => $sid]) + [SessionLifetime::CLAIM => $signedInAt];
        $refreshToken = Auth::claims($claims)->setTTL($minutes)->tokenById($user->id);

        $cookie = RefreshCookie::make($refreshToken, $minutes);

        return response()->json([
            'status' => 'success',
            'message' => 'Login successful',
            'data' => [
                'access_token' => $token,
                'token_type' => 'Bearer',
                'expires_in' => Auth::factory()->getTTL() * 60,
                'guest' => $user->is_guest
            ]
        ])->withCookie($cookie);
    }
}
