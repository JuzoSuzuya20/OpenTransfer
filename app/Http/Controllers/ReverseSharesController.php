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


class ReverseSharesController extends Controller
{
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

        $validator = Validator::make($request->all(), [
            'recipient_name' => ['required', 'string', 'max:255'],
            'recipient_email' => ['required', 'email', 'max:255']
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

        // Check if recipient is an existing non-guest user
        $existingUser = User::where('email', $request->recipient_email)
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

            // Generate a token only for guest users
            $token = auth()->tokenById($guestUser->id);
            $encryptedToken = Crypt::encryptString($token);
        }

        $invite = ReverseShareInvite::create([
            'user_id' => $user->id,
            'guest_user_id' => $guestUserId,
            'recipient_name' => $request->recipient_name,
            'recipient_email' => $request->recipient_email,
            'message' => $request->message,
            'expires_at' => now()->addDays(7)
        ]);

        sendEmail::dispatch($request->recipient_email, reverseShareInviteMail::class, [
            'user' => $user,
            'invite' => $invite,
            'token' => $encryptedToken, // Will be null for existing users
            'isExistingUser' => $existingUser !== null
        ]);

        return response()->json([
            'status' => 'success',
            'data' => [
                'invite' => $invite
            ]
        ]);
    }
}
