<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * An access token stays valid an hour after it was issued: disabling an account (someone who
 * left) must take effect at once. The refresh already refuses a disabled account.
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

        return $next($request);
    }
}
