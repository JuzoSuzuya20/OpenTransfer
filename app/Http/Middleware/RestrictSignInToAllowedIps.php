<?php

namespace App\Http\Middleware;

use App\Services\IpAllowlist;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Applies the IP allowlist to whatever gives or uses a member session. Share
 * links and reverse-share guests stay reachable from anywhere.
 * Adapted from the moutonnoireu/erugo-security fork.
 */
class RestrictSignInToAllowedIps
{
    private const SIGN_IN_ROUTES = [
        'auth.login',
        'auth.forgotPassword',
        'auth.resetPassword',
        'auth.register',
        'auth.verifyEmail',
        'auth.resendVerification',
        'social.provider.login',
        'social.provider.callback',
        'social.provider.link',
    ];

    public function __construct(private IpAllowlist $allowlist)
    {
    }

    public function handle(Request $request, Closure $next): Response
    {
        $routeName = $request->route()?->getName();
        $signingIn = in_array($routeName, self::SIGN_IN_ROUTES, true);
        $sessionToken = $routeName === 'auth.refresh' ? $request->cookie('refresh_token') : $request->bearerToken();

        // Anonymous traffic has nothing to restrict: no need to read the settings
        if (!$signingIn && !$sessionToken) {
            return $next($request);
        }

        if ($this->allowlist->blocks($request->ip()) && ($signingIn || $this->belongsToMember($sessionToken))) {
            return $this->deny($request);
        }

        return $next($request);
    }

    private function belongsToMember(string $token): bool
    {
        try {
            $user = Auth::guard('api')->setToken($token)->user();
        } catch (\Throwable) {
            // Not a token at all: the routes behind answer as for any invalid token
            return false;
        }

        return $user !== null && !$user->is_guest;
    }

    private function deny(Request $request): Response
    {
        $message = 'Sign-in is not allowed from your network.';

        if ($request->is('api/*')) {
            return response()->json(['status' => 'error', 'code' => 'ip_not_allowed', 'message' => $message], 403);
        }

        return response($message, 403)->header('Content-Type', 'text/plain');
    }
}
