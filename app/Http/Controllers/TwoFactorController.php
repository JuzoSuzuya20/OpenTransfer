<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\SettingsService;
use App\Services\Totp;
use App\Services\TwoFactor;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;

/**
 * Second factor: signing in with it, setting it up, managing it.
 * Adapted from the moutonnoireu/erugo-security fork.
 */
class TwoFactorController extends Controller
{
    use IssuesTokens;

    private const PENDING_TOTP_TTL = 900;

    public function __construct(private TwoFactor $twoFactor)
    {
    }

    // ===== signing in =====================================================

    public function verifyLogin(Request $request): JsonResponse
    {
        $request->validate([
            'mfa_token' => 'required|string',
            'method' => 'required|in:totp,recovery',
            'code' => 'required|string|max:64',
        ]);

        $user = $this->twoFactor->userForChallenge($request->mfa_token);
        if (!$user) {
            return response()->json([
                'status' => 'error',
                'code' => 'mfa_expired',
                'message' => 'Sign-in expired, please start again',
            ], 401);
        }

        if ($this->twoFactor->isLocked($user)) {
            return $this->tooManyAttempts();
        }

        $valid = $request->input('method') === 'totp'
            ? $this->twoFactor->verifyTotp($user, $request->code)
            : $this->twoFactor->useRecoveryCode($user, $request->code);

        if (!$valid) {
            $this->twoFactor->recordFailure($user);

            return response()->json([
                'status' => 'error',
                'code' => 'mfa_invalid_code',
                'message' => 'Invalid code',
            ], 401);
        }

        $this->twoFactor->clearFailures($user);
        $this->twoFactor->completeChallenge($request->mfa_token);

        return $this->respondWithToken($user->fresh());
    }

    // ===== managing it from the profile ===================================

    public function status(): JsonResponse
    {
        $user = Auth::user();

        return response()->json([
            'status' => 'success',
            'data' => [
                'enabled' => $this->twoFactor->isEnabledFor($user),
                'totp_enabled' => $user->totp_enabled_at !== null,
                'recovery_codes_remaining' => $this->twoFactor->remainingRecoveryCodes($user),
                'required' => $this->twoFactor->isRequiredFor($user),
            ],
        ]);
    }

    public function totpSetup(Request $request): JsonResponse
    {
        $user = Auth::user();

        return $this->refusePassword($request, $user) ?? $this->startTotp($user);
    }

    public function totpConfirm(Request $request): JsonResponse
    {
        $request->validate(['code' => 'required|string|max:16']);

        return $this->confirmTotp(Auth::user(), $request->code);
    }

    public function totpDisable(Request $request): JsonResponse
    {
        $user = Auth::user();

        $refusal = $this->refusePassword($request, $user) ?? $this->refuseIfRequired($user);
        if ($refusal) {
            return $refusal;
        }

        $this->twoFactor->disableTotp($user);

        return $this->status();
    }

    public function regenerateRecoveryCodes(Request $request): JsonResponse
    {
        $user = Auth::user();

        $refusal = $this->refusePassword($request, $user);
        if ($refusal) {
            return $refusal;
        }

        if (!$this->twoFactor->isEnabledFor($user)) {
            return response()->json([
                'status' => 'error',
                'code' => 'mfa_not_enabled',
                'message' => 'Two-factor authentication is not enabled',
            ], 422);
        }

        return response()->json([
            'status' => 'success',
            'data' => ['recovery_codes' => $this->twoFactor->regenerateRecoveryCodes($user)],
        ]);
    }

    // ===== mandatory enrolment ============================================
    //
    // A correct password from a user who must have a second factor but has
    // none gets a token that only allows setting one up. The session is
    // issued once that is done.

    public function enrolTotpSetup(Request $request): JsonResponse
    {
        $request->validate(['enrol_token' => 'required|string']);

        $user = $this->twoFactor->userForEnrolment($request->enrol_token);

        return $user ? $this->startTotp($user) : $this->enrolmentExpired();
    }

    public function enrolTotpConfirm(Request $request): JsonResponse
    {
        $request->validate(['enrol_token' => 'required|string', 'code' => 'required|string|max:16']);

        $user = $this->twoFactor->userForEnrolment($request->enrol_token);
        if (!$user) {
            return $this->enrolmentExpired();
        }

        $confirmation = $this->confirmTotp($user, $request->code);
        if (!$confirmation->isSuccessful()) {
            return $confirmation;
        }

        $this->twoFactor->completeEnrolment($request->enrol_token);

        // The session, with the recovery codes that go with the first factor
        $session = $this->respondWithToken($user->fresh());
        $data = $session->getData(true);
        $data['data']['recovery_codes'] = $confirmation->getData(true)['data']['recovery_codes'];

        return $session->setData($data);
    }

    private function enrolmentExpired(): JsonResponse
    {
        return response()->json([
            'status' => 'error',
            'code' => 'mfa_enrolment_expired',
            'message' => 'Setup expired, please sign in again',
        ], 401);
    }

    // ===== when the device is lost ========================================

    public function adminReset(int $id): JsonResponse
    {
        $user = User::findOrFail($id);
        $this->twoFactor->disableAll($user);

        Log::info('Two-factor authentication removed by an admin', ['user' => $user->id, 'admin' => Auth::id()]);

        return response()->json(['status' => 'success', 'message' => 'Two-factor authentication removed']);
    }

    // ===== shared =========================================================

    /**
     * A new secret, kept aside until a code proves the app has it.
     */
    private function startTotp(User $user): JsonResponse
    {
        $secret = Totp::generateSecret();
        Cache::put($this->pendingTotpKey($user), encrypt($secret), self::PENDING_TOTP_TTL);

        return response()->json([
            'status' => 'success',
            'data' => [
                'secret' => $secret,
                'otpauth_uri' => Totp::provisioningUri($secret, $user->email, $this->issuer()),
            ],
        ]);
    }

    private function confirmTotp(User $user, string $code): JsonResponse
    {
        $pending = Cache::get($this->pendingTotpKey($user));
        if (!$pending) {
            return response()->json([
                'status' => 'error',
                'code' => 'totp_setup_expired',
                'message' => 'Setup expired, please start again',
            ], 422);
        }

        $secret = decrypt($pending);
        $step = Totp::verify($secret, $code);
        if ($step === null) {
            return response()->json([
                'status' => 'error',
                'code' => 'mfa_invalid_code',
                'message' => 'Invalid code',
            ], 422);
        }

        $firstFactor = !$this->twoFactor->isEnabledFor($user);
        $user->forceFill([
            'totp_secret' => $secret,
            'totp_enabled_at' => now(),
            'totp_last_timestep' => $step,
        ])->save();
        Cache::forget($this->pendingTotpKey($user));

        return response()->json([
            'status' => 'success',
            'data' => [
                'recovery_codes' => $firstFactor ? $this->twoFactor->regenerateRecoveryCodes($user) : null,
            ],
        ]);
    }

    /**
     * Every change needs the password: a stolen session must not be able to
     * take the account for good by replacing its second factor.
     */
    private function refusePassword(Request $request, User $user): ?JsonResponse
    {
        if ($this->twoFactor->isLocked($user)) {
            return $this->tooManyAttempts();
        }

        $password = (string) $request->input('password', '');
        if ($password === '' || !Hash::check($password, $user->password)) {
            $this->twoFactor->recordFailure($user);

            return response()->json([
                'status' => 'error',
                'code' => 'invalid_password',
                'message' => 'Current password is incorrect',
            ], 422);
        }

        $this->twoFactor->clearFailures($user);

        return null;
    }

    private function refuseIfRequired(User $user): ?JsonResponse
    {
        if (!$this->twoFactor->isRequiredFor($user)) {
            return null;
        }

        return response()->json([
            'status' => 'error',
            'code' => 'mfa_required_by_policy',
            'message' => 'Two-factor authentication is required for your account',
        ], 422);
    }

    private function tooManyAttempts(): JsonResponse
    {
        return response()->json([
            'status' => 'error',
            'code' => 'mfa_too_many_attempts',
            'message' => 'Too many failed attempts, please try again later',
        ], 429);
    }

    private function pendingTotpKey(User $user): string
    {
        return 'mfa:totp-setup:'.$user->id;
    }

    private function issuer(): string
    {
        return app(SettingsService::class)->get('application_name') ?: 'OpenTransfer';
    }
}
