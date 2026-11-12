<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        $middleware->append(\App\Http\Middleware\SecurityHeaders::class);
        $middleware->encryptCookies(except: [
            'refresh_token',
        ]);
        // The client address and nothing else: the host and scheme build the SSO
        // callback URL declared at the identity providers. See config/trustedproxy.php.
        $middleware->trustProxies(headers: Request::HEADER_X_FORWARDED_FOR);
        $middleware->appendToGroup('api', \App\Http\Middleware\RestrictSignInToAllowedIps::class);
        $middleware->appendToGroup('api', \App\Http\Middleware\RejectDisabledAccounts::class);
        $middleware->appendToGroup('web', \App\Http\Middleware\RestrictSignInToAllowedIps::class);
        // No "login" route here: signing in happens in the application at the root
        $middleware->redirectGuestsTo('/');
    })
    ->withExceptions(function (Exceptions $exceptions) {
        // JSON for the whole API, whatever the client accepts: a 401 and not a redirect
        $exceptions->shouldRenderJsonWhen(fn (Request $request) => $request->is('api/*'));
    })
    ->create();
