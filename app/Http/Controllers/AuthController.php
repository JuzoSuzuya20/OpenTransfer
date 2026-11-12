<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;

use App\Models\User;
use Illuminate\Support\Facades\Validator;
use App\Mail\passwordResetMail;
use App\Jobs\sendEmail;
use Illuminate\Auth\Events\PasswordReset;
use App\Models\ReverseShareInvite;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\RateLimiter;
use App\Services\SsoPolicy;
use App\Services\SsoSessions;
use App\Services\TwoFactor;
use App\Support\RefreshCookie;


class AuthController extends Controller
{
    use IssuesTokens;

    public function login(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'email' => 'required|string|email',
            'password' => 'required|string',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => 'error',
                'message' => 'Validation failed',
                'data' => [
                    'errors' => $validator->errors()
                ]
            ], 422);
        }

        $credentials = $request->only('email', 'password');

        // Behind a reverse proxy every client shares the proxy IP, so the email
        // is what really scopes the lock: an attacker can delay one account, not all.
        $throttleKey = 'login:' . Str::lower($request->input('email')) . '|' . $request->ip();
        if (RateLimiter::tooManyAttempts($throttleKey, 5)) {
            return response()->json([
                'status' => 'error',
                'code' => 'too_many_attempts',
                'message' => 'Too many login attempts. Please try again in ' . RateLimiter::availableIn($throttleKey) . ' seconds.'
            ], 429);
        }

        // Without opening a session: a second factor may be needed first
        if (!Auth::attempt($credentials, false)) {
            RateLimiter::hit($throttleKey, 60);
            return response()->json([
                'status' => 'error',
                'message' => 'Invalid email or password'
            ], 401);
        }
        RateLimiter::clear($throttleKey);
        $user = Auth::getLastAttempted();

        if (!$user->active) {
            return $this->inactiveAccountResponse($user);
        }

        if (app(SsoPolicy::class)->forbidsPassword($user)) {
            return SsoPolicy::refusal();
        }

        $twoFactor = app(TwoFactor::class);

        if ($twoFactor->isEnabledFor($user)) {
            return response()->json([
                'status' => 'mfa_required',
                'message' => 'Second factor required',
                'data' => [
                    'mfa_token' => $twoFactor->createLoginChallenge($user),
                    'methods' => $twoFactor->methodsFor($user),
                    'expires_in' => TwoFactor::CHALLENGE_TTL,
                ]
            ]);
        }

        if ($twoFactor->isRequiredFor($user)) {
            return response()->json([
                'status' => 'mfa_enrolment_required',
                'message' => 'Two-factor authentication must be set up',
                'data' => [
                    'enrol_token' => $twoFactor->createEnrolmentChallenge($user),
                    'expires_in' => TwoFactor::ENROLMENT_TTL,
                ]
            ]);
        }

        return $this->respondWithToken($user);
    }

    /**
     * Inactive accounts include self-registered users who have not verified
     * their email yet: without this check, registering with an address you
     * do not own was enough to get in.
     */
    private function inactiveAccountResponse(User $user)
    {
        return response()->json([
            'status' => 'error',
            'code' => $user->email_verification_code ? 'email_not_verified' : 'account_disabled',
            'message' => $user->email_verification_code
                ? 'Please verify your email address before signing in'
                : 'This account is disabled'
        ], 403);
    }

    //refresh the token
    public function refresh()
    {
        //grab the token from refresh_token cookie
        $refreshToken = request()->cookie('refresh_token');
        if (!$refreshToken) {
            return response()->json([
                'status' => 'error',
                'message' => 'Unauthorized'
            ], 401);
        }

        //get the user from the token
        $user = Auth::setToken($refreshToken)->user();

        if (!$user) {
            return response()->json([
                'status' => 'error',
                'message' => 'Unauthorized'
            ], 401);
        }

        if (!$user->active) {
            return $this->inactiveAccountResponse($user);
        }

        // Password sessions of users who must have a second factor but have none are
        // not renewed: they sign in again and go through the setup. SSO is not concerned.
        $amr = rescue(fn () => Auth::getPayload()->get('amr'), null, false);
        $amr = in_array($amr, ['sso', 'passkey'], true) ? $amr : 'pwd';
        if ($amr !== 'sso' && app(SsoPolicy::class)->forbidsPassword($user)) {
            return SsoPolicy::refusal(401);
        }
        $twoFactor = app(TwoFactor::class);
        if ($amr !== 'sso' && $twoFactor->isRequiredFor($user) && !$twoFactor->isEnabledFor($user)) {
            return response()->json([
                'status' => 'error',
                'code' => 'mfa_enrolment_required',
                'message' => 'Two-factor authentication must be set up: please sign in again'
            ], 401);
        }

        $sid = $amr === 'sso' ? rescue(fn () => Auth::getPayload()->get('sid'), null, false) : null;

        return $this->respondWithToken($user, $amr, is_string($sid) ? $sid : null);
    }

    //logout the user
    public function logout()
    {
        $refreshToken = request()->cookie(RefreshCookie::NAME);

        // The session at the identity provider, if it was opened there and asked to be closed too
        $logoutUrl = SsoSessions::logoutUrl($refreshToken);

        // Both tokens stop working on the server, not only in this browser: the access token of
        // the page, and the refresh token of the cookie that a copy taken earlier would keep using
        $tokens = array_filter([request()->bearerToken(), $refreshToken ? urldecode($refreshToken) : null]);
        foreach ($tokens as $token) {
            rescue(fn () => Auth::setToken($token)->invalidate(), null, false);
        }

        return response()->json([
            'status' => 'success',
            'message' => 'Logout successful',
            'data' => ['logout_url' => $logoutUrl],
        ])->withCookie(RefreshCookie::forget());
    }

    public function acceptReverseShareInvite(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'token' => 'required|string',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => 'error',
                'message' => 'Validation failed',
                'data' => [
                    'errors' => $validator->errors()
                ]
            ], 422);
        }

        // A link cut short or altered is a refusal, not a server error
        try {
            $token = Crypt::decryptString($request->token);
        } catch (\Illuminate\Contracts\Encryption\DecryptException) {
            return response()->json([
                'status' => 'error',
                'message' => 'Unauthorized'
            ], 401);
        }

        $user = Auth::setToken($token)->user();

        if (!$user) {
            return response()->json([
                'status' => 'error',
                'message' => 'Unauthorized'
            ], 401);
        }

        $invite = ReverseShareInvite::where('guest_user_id', $user->id)->first();

        if (!$invite) {
            return response()->json([
                'status' => 'error',
                'message' => 'Invite not found'
            ], 404);
        }

        if ($invite->isExpired()) {
            return response()->json([
                'status' => 'error',
                'message' => 'Invite expired'
            ], 404);
        }

        if ($invite->isUsed()) {
            return response()->json([
                'status' => 'error',
                'message' => 'Invite already used'
            ], 404);
        }

        $invite->markAsUsed();

        //invalidate the token
        auth()->invalidate();

        return $this->respondWithToken($user);
    }

    public function forgotPassword(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'email' => 'required|string|email',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => 'error',
                'message' => 'Validation failed',
                'data' => [
                    'errors' => $validator->errors()
                ]
            ], 422);
        }

        $user = User::where('email', $request->email)->first();

        if (!$user || app(SsoPolicy::class)->forbidsPassword($user)) {
            // just respond with success so we don't leak information
            return response()->json([
                'status' => 'success',
                'message' => 'Password reset email sent'
            ]);
        }

        // createToken() skips the broker's own wait: one message a minute per address, the same
        // answer either way (asked again and again, the form would fill a mailbox)
        if (Password::broker()->getRepository()->recentlyCreatedToken($user)) {
            return response()->json([
                'status' => 'success',
                'message' => 'Password reset email sent'
            ]);
        }

        $token = Password::createToken($user);

        sendEmail::dispatch($user->email, passwordResetMail::class, ['token' => $token, 'user' => $user]);

        return response()->json([
            'status' => 'success',
            'message' => 'Password reset email sent'
        ]);
    }

    public function resetPassword(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'token' => 'required|string',
            'email' => 'required|string|email',
            'password' => 'required|string|min:8|confirmed',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => 'error',
                'message' => 'Validation failed',
                'data' => [
                    'errors' => $validator->errors()
                ]
            ], 422);
        }

        $resetting = User::where('email', $request->email)->first();
        if ($resetting && app(SsoPolicy::class)->forbidsPassword($resetting)
            && Password::broker()->tokenExists($resetting, (string) $request->token)) {
            return SsoPolicy::refusal();
        }

        $status = Password::reset(
            $request->only('email', 'password', 'password_confirmation', 'token'),
            function ($user, $password) {
                $user->forceFill([
                    'password' => Hash::make($password),
                    'remember_token' => Str::random(60),
                ])->save();

                event(new PasswordReset($user));
            }
        );

        $twentyFourHours = 60 * 60 * 24;
        if ($status === Password::PASSWORD_RESET) {
            $user = User::where('email', $request->email)->first();

            // Whoever controls the mailbox can get here: this must not be a way around the
            // second factor. The new password then goes through the normal sign-in.
            $twoFactor = app(TwoFactor::class);
            if ($twoFactor->isEnabledFor($user) || $twoFactor->isRequiredFor($user)) {
                return response()->json([
                    'status' => 'success',
                    'message' => 'Password reset successfully'
                ]);
            }

            $refreshToken = Auth::setTTL($twentyFourHours)->tokenById($user->id);
            return response()->json([
                'status' => 'success',
                'message' => 'Password reset successfully'
            ])->withCookie(RefreshCookie::make($refreshToken, $twentyFourHours));
        }

        return response()->json([
            'status' => 'error',
            'message' => 'Password reset failed'
        ], 400);
    }

    /**
     * Accept a reverse share invite by ID (for existing/authenticated users)
     * This requires the user to be logged in and their email must match the invite's recipient_email
     */
    public function acceptReverseShareInviteById(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'invite_id' => 'required|integer',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => 'error',
                'message' => 'Validation failed',
                'data' => [
                    'errors' => $validator->errors()
                ]
            ], 422);
        }

        $user = Auth::user();

        if (!$user) {
            return response()->json([
                'status' => 'error',
                'message' => 'Unauthorized'
            ], 401);
        }

        $invite = ReverseShareInvite::find($request->invite_id);

        if (!$invite) {
            return response()->json([
                'status' => 'error',
                'message' => 'Invite not found'
            ], 404);
        }

        // Verify the logged-in user's email matches the invite's recipient_email
        if (strtolower($user->email) !== strtolower($invite->recipient_email)) {
            return response()->json([
                'status' => 'error',
                'message' => 'This invite was sent to a different email address'
            ], 403);
        }

        if ($invite->isExpired()) {
            return response()->json([
                'status' => 'error',
                'message' => 'Invite expired'
            ], 400);
        }

        if ($invite->isUsed()) {
            return response()->json([
                'status' => 'error',
                'message' => 'Invite already used'
            ], 400);
        }

        // Store the active invite ID on the user for the upload process to reference
        // We'll use the session or a simple approach - store it in the invite itself
        // by setting the guest_user_id to the current user's ID
        $invite->guest_user_id = $user->id;
        $invite->markAsUsed(); // Mark as used so UploadsController can find it

        return response()->json([
            'status' => 'success',
            'message' => 'Invite accepted. You can now upload files.',
            'data' => [
                'invite' => $invite
            ]
        ]);
    }
}
