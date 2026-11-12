<?php

namespace App\Http\Controllers;

use App\Support\RefreshCookie;
use Illuminate\Support\Facades\Auth;

/**
 * Issues the access token and the refresh cookie of a user.
 */
trait IssuesTokens
{
    /**
     * @param string $amr how the session was opened ("pwd", "passkey" or "sso"), kept across
     *                    renewals: the second factor policy only covers passwords
     */
    protected function respondWithToken($user, string $amr = 'pwd', ?string $sid = null)
    {
        $token = Auth::login($user);

        if (!$token) {
            return response()->json([
                'status' => 'error',
                'message' => 'Unauthorized'
            ], 401);
        }

        $twentyFourHours = 60 * 60 * 24;
        $refreshToken = Auth::claims(array_filter(['amr' => $amr, 'sid' => $sid]))->setTTL($twentyFourHours)->tokenById($user->id);

        $cookie = RefreshCookie::make($refreshToken, $twentyFourHours);

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
