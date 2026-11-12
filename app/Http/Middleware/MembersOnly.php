<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * A file request gives the person outside a guest account, whose token travels by e-mail: it
 * drops files for the member who asked and nothing more. The routes behind this middleware act
 * as a member (write to other people, change the account, set up its sign-in).
 */
class MembersOnly
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->user()?->is_guest) {
            return response()->json([
                'status' => 'error',
                'code' => 'guest_not_allowed',
                'message' => 'A guest account only drops the files it was asked for',
            ], 403);
        }

        return $next($request);
    }
}
