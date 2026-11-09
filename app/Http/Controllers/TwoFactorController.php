<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\Passkeys;
use App\Services\SettingsService;
use App\Services\Totp;
use App\Services\TwoFactor;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

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
            return $this->challengeExpired();
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

    public function passkeyLoginOptions(Request $request, Passkeys $passkeys): JsonResponse
    {
        $request->validate(['mfa_token' => 'required|string']);

        $user = $this->twoFactor->userForChallenge($request->mfa_token);
        if (!$user || !$user->passkeys()->exists()) {
            return $this->challengeExpired();
        }

        return response()->json([
            'status' => 'success',
            'data' => ['options' => $passkeys->authenticationOptions($user, $request->mfa_token)],
        ]);
    }

    public function passkeyLogin(Request $request, Passkeys $passkeys): JsonResponse
    {
        $request->validate([
            'mfa_token' => 'required|string',
            'credential' => 'required|array',
        ]);

        $user = $this->twoFactor->userForChallenge($request->mfa_token);
        if (!$user) {
            return $this->challengeExpired();
        }

        if ($this->twoFactor->isLocked($user)) {
            return $this->tooManyAttempts();
        }

        if (!$passkeys->verify($user, $request->mfa_token, $request->input('credential'))) {
            $this->twoFactor->recordFailure($user);

            return response()->json([
                'status' => 'error',
                'code' => 'mfa_invalid_passkey',
                'message' => 'Passkey verification failed',
            ], 401);
        }

        $this->twoFactor->clearFailures($user);
        $this->twoFactor->completeChallenge($request->mfa_token);

        return $this->respondWithToken($user->fresh());
    }

    private function challengeExpired(): JsonResponse
    {
        return response()->json([
            'status' => 'error',
            'code' => 'mfa_expired',
            'message' => 'Sign-in expired, please start again',
        ], 401);
    }

    // ===== signing in with a passkey alone (when an admin allows it) ======

    public function passwordlessOptions(Passkeys $passkeys): JsonResponse
    {
        if (!$this->passwordlessEnabled()) {
            return $this->passwordlessDisabled();
        }

        $token = Str::random(64);

        return response()->json([
            'status' => 'success',
            'data' => ['token' => $token, 'options' => $passkeys->discoverableOptions($token)],
        ]);
    }

    public function passwordlessLogin(Request $request, Passkeys $passkeys): JsonResponse
    {
        if (!$this->passwordlessEnabled()) {
            return $this->passwordlessDisabled();
        }

        $request->validate([
            'token' => 'required|string',
            'credential' => 'required|array',
        ]);

        $user = $passkeys->verifyDiscoverable($request->token, $request->input('credential'));
        if (!$user || !$user->active || $user->is_guest) {
            return response()->json([
                'status' => 'error',
                'code' => 'passkey_login_failed',
                'message' => 'This passkey could not be used to sign in',
            ], 401);
        }

        // With user verification, a passkey is already two factors: the device and its PIN or biometrics
        return $this->respondWithToken($user, 'passkey');
    }

    private function passwordlessEnabled(): bool
    {
        return app(SettingsService::class)->get(TwoFactor::SETTING_PASSWORDLESS) === true;
    }

    private function passwordlessDisabled(): JsonResponse
    {
        return response()->json(['status' => 'error', 'message' => 'Passkey sign-in is not enabled'], 404);
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
                'passkeys' => $user->passkeys()->orderBy('created_at')->get(['id', 'name', 'created_at', 'last_used_at']),
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

        $refusal = $this->refusePassword($request, $user) ?? $this->refuseIfLastFactor($user, removesTotp: true);
        if ($refusal) {
            return $refusal;
        }

        $this->twoFactor->disableTotp($user);

        return $this->status();
    }

    public function passkeyRegistrationOptions(Request $request, Passkeys $passkeys): JsonResponse
    {
        $user = Auth::user();

        return $this->refusePassword($request, $user) ?? response()->json([
            'status' => 'success',
            'data' => ['options' => $passkeys->registrationOptions($user)],
        ]);
    }

    public function passkeyRegister(Request $request, Passkeys $passkeys): JsonResponse
    {
        $request->validate([
            'name' => 'nullable|string|max:100',
            'credential' => 'required|array',
        ]);

        return $this->registerPasskey(Auth::user(), $request, $passkeys);
    }

    public function passkeyDelete(Request $request, int $id): JsonResponse
    {
        $user = Auth::user();

        $refusal = $this->refusePassword($request, $user) ?? $this->refuseIfLastFactor($user, removesPasskeyId: $id);
        if ($refusal) {
            return $refusal;
        }

        $this->twoFactor->removePasskey($user, $id);

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

        return $this->finishEnrolment($request->enrol_token, $user, $this->confirmTotp($user, $request->code));
    }

    public function enrolPasskeyOptions(Request $request, Passkeys $passkeys): JsonResponse
    {
        $request->validate(['enrol_token' => 'required|string']);

        $user = $this->twoFactor->userForEnrolment($request->enrol_token);
        if (!$user) {
            return $this->enrolmentExpired();
        }

        return response()->json([
            'status' => 'success',
            'data' => ['options' => $passkeys->registrationOptions($user)],
        ]);
    }

    public function enrolPasskey(Request $request, Passkeys $passkeys): JsonResponse
    {
        $request->validate([
            'enrol_token' => 'required|string',
            'name' => 'nullable|string|max:100',
            'credential' => 'required|array',
        ]);

        $user = $this->twoFactor->userForEnrolment($request->enrol_token);
        if (!$user) {
            return $this->enrolmentExpired();
        }

        return $this->finishEnrolment($request->enrol_token, $user, $this->registerPasskey($user, $request, $passkeys));
    }

    /**
     * The session, with the recovery codes that go with the first factor.
     */
    private function finishEnrolment(string $token, User $user, JsonResponse $factor): JsonResponse
    {
        if (!$factor->isSuccessful()) {
            return $factor;
        }

        $this->twoFactor->completeEnrolment($token);

        $session = $this->respondWithToken($user->fresh());
        $data = $session->getData(true);
        $data['data']['recovery_codes'] = $factor->getData(true)['data']['recovery_codes'];

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

    private function registerPasskey(User $user, Request $request, Passkeys $passkeys): JsonResponse
    {
        $firstFactor = !$this->twoFactor->isEnabledFor($user);

        $passkey = $passkeys->register($user, $request->input('credential'), (string) $request->input('name', ''));
        if (!$passkey) {
            return response()->json([
                'status' => 'error',
                'code' => 'passkey_registration_failed',
                'message' => 'The passkey could not be registered',
            ], 422);
        }

        return response()->json([
            'status' => 'success',
            'data' => [
                'passkey' => $passkey->only(['id', 'name', 'created_at', 'last_used_at']),
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

    /**
     * Keeps at least one factor when the policy requires one for this user.
     */
    private function refuseIfLastFactor(User $user, bool $removesTotp = false, ?int $removesPasskeyId = null): ?JsonResponse
    {
        if (!$this->twoFactor->isRequiredFor($user)) {
            return null;
        }

        $totpLeft = $user->totp_enabled_at !== null && !$removesTotp;
        $passkeysLeft = $user->passkeys()->when($removesPasskeyId, fn ($query) => $query->whereKeyNot($removesPasskeyId))->exists();
        if ($totpLeft || $passkeysLeft) {
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
