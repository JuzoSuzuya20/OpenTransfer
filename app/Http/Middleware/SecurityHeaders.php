<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Foundation\Vite;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Defence-in-depth headers on every response the app serves. Set here rather
 * than only in the bundled Caddyfile so they also apply behind another server.
 */
class SecurityHeaders
{
    // Inline styles stay allowed: Vue style bindings and the theme variables need them.
    // Scripts are strictly first-party, which is what blocks injected markup from running.
    private const CSP = "default-src 'self'; script-src 'self'; style-src 'self' 'unsafe-inline'; "
        . "img-src 'self' data: blob:; font-src 'self' data:; connect-src 'self'; media-src 'self' blob:; "
        . "worker-src 'self' blob:; object-src 'none'; base-uri 'self'; form-action 'self'; frame-ancestors 'self'";

    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);
        $headers = $response->headers;

        // A response may already carry a stricter policy of its own (SVG favicon).
        // The Vite dev server is another origin: no CSP while it serves the assets.
        if (!$headers->has('Content-Security-Policy') && !app(Vite::class)->isRunningHot()) {
            $headers->set('Content-Security-Policy', self::CSP);
        }

        // Share ids are in the URL: never send them to another site
        $headers->set('Referrer-Policy', 'no-referrer');
        $headers->set('X-Content-Type-Options', 'nosniff');
        $headers->set('Cross-Origin-Opener-Policy', 'same-origin');
        $headers->set('Permissions-Policy', 'camera=(), microphone=(), geolocation=(), payment=(), usb=(), interest-cohort=()');
        $headers->remove('X-Powered-By');

        return $response;
    }
}
