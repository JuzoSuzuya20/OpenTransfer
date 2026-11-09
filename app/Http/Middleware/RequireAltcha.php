<?php

namespace App\Http\Middleware;

use App\Services\Altcha;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * When the proof of work is on, the form must come with an unused solution.
 */
class RequireAltcha
{
    public function __construct(private Altcha $altcha)
    {
    }

    public function handle(Request $request, Closure $next): Response
    {
        if (!$this->altcha->isEnabled() || $this->altcha->verify($request->input('altcha'))) {
            return $next($request);
        }

        return response()->json([
            'status' => 'error',
            'code' => 'altcha_invalid',
            'message' => 'The anti-robot verification failed, please try again',
        ], 422);
    }
}
