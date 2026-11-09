<?php

namespace App\Services;

use ParagonIE\ConstantTime\Base32;

/**
 * RFC 6238 time-based one-time passwords (SHA-1, 6 digits, 30 seconds), the
 * format every authenticator app understands.
 */
class Totp
{
    private const PERIOD = 30;
    private const DIGITS = 6;

    /** Accepted clock drift, in time steps, on each side. */
    private const WINDOW = 1;

    public static function generateSecret(): string
    {
        // 160 bits, as recommended by RFC 4226
        return Base32::encodeUpperUnpadded(random_bytes(20));
    }

    public static function provisioningUri(string $secret, string $account, string $issuer): string
    {
        return 'otpauth://totp/'.rawurlencode($issuer).':'.rawurlencode($account).'?'.http_build_query([
            'secret' => $secret,
            'issuer' => $issuer,
            'algorithm' => 'SHA1',
            'digits' => self::DIGITS,
            'period' => self::PERIOD,
        ], '', '&', PHP_QUERY_RFC3986);
    }

    public static function timestep(?int $time = null): int
    {
        return intdiv($time ?? time(), self::PERIOD);
    }

    public static function codeAt(string $secret, int $timestep): string
    {
        $hash = hash_hmac('sha1', pack('J', $timestep), Base32::decodeUpper($secret), true);
        $offset = ord($hash[strlen($hash) - 1]) & 0x0F;
        $value = unpack('N', substr($hash, $offset, 4))[1] & 0x7FFFFFFF;

        return str_pad((string) ($value % (10 ** self::DIGITS)), self::DIGITS, '0', STR_PAD_LEFT);
    }

    /**
     * The time step this code belongs to, or null. A step at or before
     * $lastUsedStep is refused so that a code cannot be replayed.
     */
    public static function verify(string $secret, string $code, ?int $lastUsedStep = null, ?int $time = null): ?int
    {
        $code = preg_replace('/\s+/', '', $code);
        if (!preg_match('/^\d{'.self::DIGITS.'}$/', $code)) {
            return null;
        }

        $current = self::timestep($time);
        for ($step = $current - self::WINDOW; $step <= $current + self::WINDOW; $step++) {
            if ($lastUsedStep !== null && $step <= $lastUsedStep) {
                continue;
            }
            if (hash_equals(self::codeAt($secret, $step), $code)) {
                return $step;
            }
        }

        return null;
    }
}
