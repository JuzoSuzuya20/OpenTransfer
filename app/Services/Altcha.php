<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;

/**
 * Self-hosted proof of work in the ALTCHA format (https://altcha.org), adapted
 * from the moutonnoireu/erugo-security fork: the browser looks for the number
 * behind sha256(salt + number), which costs a bot for every attempt. Challenges
 * are signed and stateless, expire, and each solution is accepted once.
 */
class Altcha
{
    public const SETTING_ENABLED = 'altcha_enabled';
    public const SETTING_MAX_NUMBER = 'altcha_max_number';

    public const MIN_MAX_NUMBER = 1000;
    public const MAX_MAX_NUMBER = 10000000;

    private const DEFAULT_MAX_NUMBER = 100000;
    private const TTL = 600;
    private const ALGORITHM = 'SHA-256';
    private const LONGEST_PAYLOAD = 4096;

    public function __construct(private SettingsService $settings)
    {
    }

    public function isEnabled(): bool
    {
        return $this->settings->get(self::SETTING_ENABLED) === true;
    }

    /**
     * @return array{algorithm: string, challenge: string, maxnumber: int, salt: string, signature: string}
     */
    public function createChallenge(): array
    {
        $maxNumber = $this->maxNumber();

        // The expiry is in the salt, so the hash and the signature cover it
        $salt = bin2hex(random_bytes(12)).'?expires='.(now()->getTimestamp() + self::TTL);
        $challenge = hash('sha256', $salt.random_int(0, $maxNumber));

        return [
            'algorithm' => self::ALGORITHM,
            'challenge' => $challenge,
            'maxnumber' => $maxNumber,
            'salt' => $salt,
            'signature' => $this->sign($challenge),
        ];
    }

    /**
     * Checks the base64 payload sent by the browser, and spends it.
     */
    public function verify(mixed $payload): bool
    {
        if (!is_string($payload) || $payload === '' || strlen($payload) > self::LONGEST_PAYLOAD) {
            return false;
        }

        $data = json_decode((string) base64_decode($payload, true), true);
        if (!is_array($data)) {
            return false;
        }

        $challenge = $data['challenge'] ?? null;
        $number = $data['number'] ?? null;
        $salt = $data['salt'] ?? null;
        $signature = $data['signature'] ?? null;

        if (($data['algorithm'] ?? null) !== self::ALGORITHM
            || !is_string($challenge) || !is_string($salt) || !is_string($signature)
            || !is_int($number) || $number < 0) {
            return false;
        }

        $expires = preg_match('/\?expires=(\d+)$/', $salt, $match) ? (int) $match[1] : 0;

        if ($expires < now()->getTimestamp()
            || !hash_equals($this->sign($challenge), $signature)
            || !hash_equals($challenge, hash('sha256', $salt.$number))) {
            return false;
        }

        // Only the first request to record this challenge passes
        return Cache::add('altcha:used:'.$challenge, true, $expires - now()->getTimestamp() + 1);
    }

    private function maxNumber(): int
    {
        $value = (int) $this->settings->get(self::SETTING_MAX_NUMBER);

        return $value >= self::MIN_MAX_NUMBER && $value <= self::MAX_MAX_NUMBER ? $value : self::DEFAULT_MAX_NUMBER;
    }

    private function sign(string $challenge): string
    {
        return hash_hmac('sha256', $challenge, hash_hmac('sha256', 'altcha', (string) config('app.key')));
    }
}
