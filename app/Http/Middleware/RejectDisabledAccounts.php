<?php

namespace App\Http\Middleware;

use App\Support\SessionRevocation;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * An access token stays valid an hour after it was issued: disabling an account (someone who
 * left), or changing its password, must take effect at once. The refresh refuses both too.
 */
class RejectDisabledAccounts
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->bearerToken() ? rescue(fn () => $request->user('api'), null, false) : null;

        if ($user && !$user->active) {
            return response()->json([
                'status' => 'error',
                'code' => 'account_disabled',
                'message' => 'This account is disabled',
            ], 401);
        }

        // An access token issued before the password last changed (SessionRevocation)
        if ($user && SessionRevocation::revoked($user, rescue(fn () => auth('api')->payload()->get('iat'), null, false))) {
            return SessionRevocation::refusal();
        }

        return $next($request);
    }
}
