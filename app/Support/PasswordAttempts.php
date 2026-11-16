<?php

namespace App\Support;

use Illuminate\Support\Facades\RateLimiter;

/**
 * A password is tried four times, then waits: the sign-in, the password of a share, the password
 * of a file request. A refusal tells the page how many tries remain (attempts_left), and the one
 * that uses the last how long to wait (retry_after, in seconds), as a wait refused later does.
 */
final class PasswordAttempts
{
    public const MAX = 4;

    public static function locked(string $key): bool
    {
        return RateLimiter::tooManyAttempts($key, self::MAX);
    }

    /** A wrong password: counted, and what the page says of it */
    public static function miss(string $key, int $decaySeconds): array
    {
        RateLimiter::hit($key, $decaySeconds);
        $left = max(0, self::MAX - RateLimiter::attempts($key));

        return $left === 0 ? ['attempts_left' => 0, 'retry_after' => self::wait($key)] : ['attempts_left' => $left];
    }

    public static function wait(string $key): int
    {
        return max(1, RateLimiter::availableIn($key));
    }

    public static function clear(string $key): void
    {
        RateLimiter::clear($key);
    }
}
