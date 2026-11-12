<?php

namespace App\Http\Controllers;

use App\Jobs\sendEmail;
use App\Mail\emailChangeMail;
use App\Models\User;
use App\Services\SsoPolicy;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

/**
 * A member moves their account to a new address only with a code sent to that address. Set
 * freely, an address would let anyone take a colleague's before their first SSO sign-in: the
 * identity provider, trusting addresses, would open the colleague's session in that account.
 */
class EmailChangeController extends Controller
{
    private const CODE_MINUTES = 15;

    private const MAX_ATTEMPTS = 5;

    public function request(Request $request)
    {
        $user = Auth::user();

        // The address of an account of the organisation is the one of its identity provider
        if (app(SsoPolicy::class)->forbidsPassword($user)) {
            return SsoPolicy::refusal();
        }

        $validator = Validator::make($request->all(), [
            'email' => ['required', 'string', 'email', 'max:255', Rule::unique('users', 'email')->ignore($user->id)],
            'current_password' => ['required', 'string'],
        ]);
        $validator->after(function ($validator) use ($request, $user) {
            if (is_string($request->input('current_password')) && !Hash::check($request->input('current_password'), $user->password)) {
                $validator->errors()->add('current_password', 'The current password is incorrect.');
            }
            if (is_string($request->input('email')) && strcasecmp($request->input('email'), $user->email) === 0) {
                $validator->errors()->add('email', 'This is already the address of the account.');
            } elseif ($this->takenIgnoringCase($request->input('email'), $user)) {
                $validator->errors()->add('email', 'The email has already been taken.');
            }
        });
        if ($validator->fails()) {
            return response()->json([
                'status' => 'error',
                'message' => 'Validation failed',
                'data' => ['errors' => $validator->errors()],
            ], 422);
        }

        $email = trim($request->input('email'));
        $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);

        $user->forceFill([
            'pending_email' => $email,
            'pending_email_code' => Hash::make($code),
            'pending_email_expires_at' => now()->addMinutes(self::CODE_MINUTES),
        ])->save();
        RateLimiter::clear($this->throttleKey($user));

        sendEmail::dispatch($email, emailChangeMail::class, ['code' => $code, 'user' => $user]);

        return response()->json([
            'status' => 'success',
            'message' => 'A code was sent to the new address',
            'data' => ['pending_email' => $email],
        ]);
    }

    public function confirm(Request $request)
    {
        $user = Auth::user();
        $key = $this->throttleKey($user);

        if (RateLimiter::tooManyAttempts($key, self::MAX_ATTEMPTS)) {
            return response()->json(['status' => 'error', 'message' => 'Too many attempts'], 429);
        }

        $code = $request->input('code');
        $valid = is_string($code)
            && $user->pending_email
            && $user->pending_email_code
            && $user->pending_email_expires_at?->isFuture()
            && Hash::check($code, $user->pending_email_code);

        if (!$valid) {
            RateLimiter::hit($key, self::CODE_MINUTES * 60);

            return response()->json(['status' => 'error', 'message' => 'Invalid or expired code'], 422);
        }

        // Another account may have taken the address since the code left
        if ($this->takenIgnoringCase($user->pending_email, $user)) {
            $this->forgetPending($user);

            return response()->json(['status' => 'error', 'message' => 'The email has already been taken'], 409);
        }

        $user->forceFill(['email' => $user->pending_email])->save();
        $this->forgetPending($user);
        RateLimiter::clear($key);

        return response()->json([
            'status' => 'success',
            'message' => 'The address of the account changed',
            'data' => ['user' => $user->fresh()],
        ]);
    }

    private function forgetPending(User $user): void
    {
        $user->forceFill(['pending_email' => null, 'pending_email_code' => null, 'pending_email_expires_at' => null])->save();
    }

    private function takenIgnoringCase(mixed $email, User $user): bool
    {
        return is_string($email) && User::whereRaw('LOWER(email) = ?', [strtolower(trim($email))])->where('id', '!=', $user->id)->exists();
    }

    private function throttleKey(User $user): string
    {
        return 'email-change:'.$user->id;
    }
}
