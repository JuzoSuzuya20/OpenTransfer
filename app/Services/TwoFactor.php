<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use ParagonIE\ConstantTime\Base32;

/**
 * Second factor for password sign-in, adapted from the moutonnoireu/erugo-security
 * fork. SSO accounts answer to their identity provider and are not concerned.
 *
 * After a correct password, a user with a second factor gets a short-lived
 * challenge token instead of a session; a user who must have one but has none
 * gets an enrolment token. The session is issued once that step is completed.
 */
class TwoFactor
{
    public const SETTING_REQUIRED_FOR = 'mfa_required_for';
    public const POLICIES = ['none', 'admins', 'everyone'];

    public const CHALLENGE_TTL = 300;
    public const ENROLMENT_TTL = 900;

    private const MAX_FAILURES = 5;
    private const LOCK_SECONDS = 900;
    private const RECOVERY_CODE_COUNT = 10;

    public function __construct(private SettingsService $settings)
    {
    }

    public function isRequiredFor(User $user): bool
    {
        if ($user->is_guest) {
            return false;
        }

        return match ($this->settings->get(self::SETTING_REQUIRED_FOR)) {
            'everyone' => true,
            'admins' => (bool) $user->admin,
            default => false,
        };
    }

    public function isEnabledFor(User $user): bool
    {
        return $user->totp_enabled_at !== null;
    }

    /** @return string[] */
    public function methodsFor(User $user): array
    {
        $methods = [];
        if ($user->totp_enabled_at !== null) {
            $methods[] = 'totp';
        }
        if ($this->remainingRecoveryCodes($user) > 0) {
            $methods[] = 'recovery';
        }

        return $methods;
    }

    // ----- sign-in challenge ---------------------------------------------

    public function createLoginChallenge(User $user): string
    {
        return $this->createToken($this->challengeKey(...), $user, self::CHALLENGE_TTL);
    }

    public function userForChallenge(?string $token): ?User
    {
        return $this->userForToken($this->challengeKey(...), $token);
    }

    public function completeChallenge(string $token): void
    {
        Cache::forget($this->challengeKey($token));
    }

    private function challengeKey(string $token): string
    {
        return 'mfa:login:'.hash('sha256', $token);
    }

    // ----- mandatory enrolment -------------------------------------------

    public function createEnrolmentChallenge(User $user): string
    {
        return $this->createToken($this->enrolmentKey(...), $user, self::ENROLMENT_TTL);
    }

    /**
     * Enrolment is for accounts without a second factor: a token obtained
     * before the user set one up must not be able to replace it.
     */
    public function userForEnrolment(?string $token): ?User
    {
        $user = $this->userForToken($this->enrolmentKey(...), $token);

        return $user && !$this->isEnabledFor($user) ? $user : null;
    }

    public function completeEnrolment(string $token): void
    {
        Cache::forget($this->enrolmentKey($token));
    }

    private function enrolmentKey(string $token): string
    {
        return 'mfa:enrol:'.hash('sha256', $token);
    }

    private function createToken(callable $key, User $user, int $ttl): string
    {
        $token = Str::random(64);
        Cache::put($key($token), $user->id, $ttl);

        return $token;
    }

    private function userForToken(callable $key, ?string $token): ?User
    {
        if (!is_string($token) || $token === '') {
            return null;
        }

        $id = Cache::get($key($token));
        $user = $id ? User::find($id) : null;

        return $user && $user->active ? $user : null;
    }

    // ----- attempts ------------------------------------------------------

    /**
     * Counted per account and not per challenge: signing in again gives a new
     * challenge, whoever has the password would get unlimited guesses.
     */
    public function isLocked(User $user): bool
    {
        return RateLimiter::tooManyAttempts($this->failuresKey($user), self::MAX_FAILURES);
    }

    public function recordFailure(User $user): void
    {
        RateLimiter::hit($this->failuresKey($user), self::LOCK_SECONDS);
    }

    public function clearFailures(User $user): void
    {
        RateLimiter::clear($this->failuresKey($user));
    }

    private function failuresKey(User $user): string
    {
        return 'mfa:failures:'.$user->id;
    }

    // ----- TOTP ----------------------------------------------------------

    public function verifyTotp(User $user, string $code): bool
    {
        if ($user->totp_enabled_at === null || !$user->totp_secret) {
            return false;
        }

        $step = Totp::verify($user->totp_secret, $code, $user->totp_last_timestep);
        if ($step === null) {
            return false;
        }

        // Of two simultaneous requests with the same code, only one may pass
        return User::whereKey($user->id)
            ->where(fn ($query) => $query->whereNull('totp_last_timestep')->orWhere('totp_last_timestep', '<', $step))
            ->update(['totp_last_timestep' => $step]) === 1;
    }

    public function disableTotp(User $user): void
    {
        $user->forceFill(['totp_secret' => null, 'totp_enabled_at' => null, 'totp_last_timestep' => null])->save();

        if (!$this->isEnabledFor($user)) {
            $user->forceFill(['two_factor_recovery_codes' => null])->save();
        }
    }

    // ----- recovery codes ------------------------------------------------

    /**
     * Replaces the codes of the user and returns the new ones, in clear for
     * the only time: just their hashes are stored.
     *
     * @return string[]
     */
    public function regenerateRecoveryCodes(User $user): array
    {
        $codes = [];
        for ($i = 0; $i < self::RECOVERY_CODE_COUNT; $i++) {
            $raw = strtolower(Base32::encodeUnpadded(random_bytes(7)));
            $codes[] = substr($raw, 0, 5).'-'.substr($raw, 5, 6);
        }

        $user->forceFill([
            'two_factor_recovery_codes' => json_encode(array_map($this->hashRecoveryCode(...), $codes)),
        ])->save();

        return $codes;
    }

    public function useRecoveryCode(User $user, string $code): bool
    {
        $candidate = $this->hashRecoveryCode($code);

        // Locked so that two simultaneous requests cannot both spend the same code
        return DB::transaction(function () use ($user, $candidate) {
            $locked = User::whereKey($user->id)->lockForUpdate()->first();
            $hashes = $this->recoveryCodeHashes($locked);

            foreach ($hashes as $index => $hash) {
                if (hash_equals($hash, $candidate)) {
                    unset($hashes[$index]);
                    $locked->forceFill(['two_factor_recovery_codes' => json_encode(array_values($hashes))])->save();

                    return true;
                }
            }

            return false;
        });
    }

    public function remainingRecoveryCodes(User $user): int
    {
        return count($this->recoveryCodeHashes($user));
    }

    /** @return string[] */
    private function recoveryCodeHashes(User $user): array
    {
        $hashes = json_decode((string) $user->two_factor_recovery_codes, true);

        return is_array($hashes) ? array_values(array_filter($hashes, 'is_string')) : [];
    }

    private function hashRecoveryCode(string $code): string
    {
        return hash('sha256', strtolower(preg_replace('/[^a-z0-9]/i', '', $code)));
    }

    /**
     * Removes every second factor of a user who lost their device.
     */
    public function disableAll(User $user): void
    {
        $user->forceFill([
            'totp_secret' => null,
            'totp_enabled_at' => null,
            'totp_last_timestep' => null,
            'two_factor_recovery_codes' => null,
        ])->save();
    }
}
