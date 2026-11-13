<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Auth;
use App\Models\User;
use App\Models\ReverseShareInvite;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Crypt;
use App\Jobs\sendEmail;
use App\Mail\reverseShareInviteMail;
use App\Models\Setting;
use App\Services\SettingsService;


class ReverseSharesController extends Controller
{
    // How long a request stays open, in days, as the page offers it
    private const VALIDITY_DAYS = [1, 3, 7, 14, 30];

    // The requests the signed-in account sent (the latest 100), each with where it is: waiting,
    // received (the guest delivered) or expired. Nothing about the guest account behind it.
    public function listInvites()
    {
        $invites = ReverseShareInvite::where('user_id', Auth::id())
            ->latest()
            ->limit(100)
            ->get()
            ->map(fn (ReverseShareInvite $invite) => [
                'id' => $invite->id,
                'recipient_name' => $invite->recipient_name,
                'recipient_email' => $invite->recipient_email,
                // Passed on by the member as a link: no address (see createInvite)
                'delivery' => $invite->recipient_email === '' ? 'link' : 'email',
                'message' => $invite->message,
                'state' => $invite->isCompleted() ? 'received' : ($invite->isExpired() ? 'expired' : 'pending'),
                'created_at' => $invite->created_at,
                'expires_at' => $invite->expires_at,
                'completed_at' => $invite->completed_at,
            ]);

        return response()->json([
            'status' => 'success',
            'message' => 'Invites retrieved',
            'data' => ['invites' => $invites],
        ]);
    }

    public function createInvite(Request $request)
    {

        $allowReverseShares = Setting::where('key', 'allow_reverse_shares')->first()->value;
        $allowReverseShares = filter_var($allowReverseShares, FILTER_VALIDATE_BOOLEAN);

        if (!$allowReverseShares) {
            return response()->json([
                'status' => 'error',
                'message' => 'Reverse shares are not allowed'
            ], 400);
        }

        // By e-mail (the address gets the link), or by link (the member passes it on: no address,
        // kept as an empty one, the column has no null)
        $byLink = $request->input('delivery') === 'link';

        $validator = Validator::make($request->all(), [
            'delivery' => ['nullable', 'in:email,link'],
            'recipient_name' => ['required', 'string', 'max:255'],
            'recipient_email' => $byLink ? ['nullable'] : ['required', 'email', 'max:255'],
            'message' => ['nullable', 'string', 'max:5000'],
            'expires_in_days' => ['nullable', 'integer', 'in:' . implode(',', self::VALIDITY_DAYS)],
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

        $days = (int) ($request->input('expires_in_days') ?: 7);

        // Check if recipient is an existing non-guest user (a link has no recipient to look up)
        $existingUser = $byLink ? null : User::where('email', $request->recipient_email)
            ->where(function ($query) {
                $query->where('is_guest', false)
                    ->orWhereNull('is_guest');
            })
            ->first();

        $encryptedToken = null;
        $guestUserId = null;

        if ($existingUser) {
            // Existing user - no token, no guest user
            // They will need to log in with their credentials
            $guestUserId = null;
        } else {
            // Create a guest user for the invite
            $guestUser = (new User())->forceFill([
                'name' => $request->recipient_name,
                'email' => Str::random(20), //we don't need a real email for the guest user
                'password' => Hash::make(Str::random(20)), //set a random password so the user can't login
                'is_guest' => true
            ]);
            $guestUser->save();
            $guestUserId = $guestUser->id;

            // Generate a token only for guest users, valid as long as the request: with the default
            // lifetime of an access token (an hour) the link stopped working the same day
            $token = auth()->setTTL($days * 24 * 60)->tokenById($guestUser->id);
            $encryptedToken = Crypt::encryptString($token);
        }

        $invite = ReverseShareInvite::create([
            'user_id' => $user->id,
            'guest_user_id' => $guestUserId,
            'recipient_name' => $request->recipient_name,
            'recipient_email' => $byLink ? '' : $request->recipient_email,
            'message' => $request->message,
            'expires_at' => now()->addDays($days)
        ]);

        if (!$byLink) {
            sendEmail::dispatch($request->recipient_email, reverseShareInviteMail::class, [
                'user' => $user,
                'invite' => $invite,
                'token' => $encryptedToken, // Will be null for existing users
                'isExistingUser' => $existingUser !== null
            ]);
        }

        // The same address an e-mail would hold, for the member to pass on
        $address = rtrim((string) (app(SettingsService::class)->get('application_url') ?: config('app.url')), '/');

        return response()->json([
            'status' => 'success',
            'data' => [
                'invite' => $invite,
                'link' => $byLink ? $address . '/?invite_token=' . urlencode($encryptedToken) : null,
            ]
        ]);
    }
}
