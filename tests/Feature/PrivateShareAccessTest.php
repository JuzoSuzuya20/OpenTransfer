<?php

namespace Tests\Feature;

use App\Models\ReverseShareInvite;
use App\Models\Share;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

// What a guest drops for a file request is read only by the member who asked, while that
// member's account is active: a disabled account (someone who left) no longer opens it, even
// with a sign-in cookie still valid.
class PrivateShareAccessTest extends TestCase
{
    use RefreshDatabase;

    protected $seed = true;

    private function droppedFor(User $member): Share
    {
        $invite = ReverseShareInvite::create([
            'user_id' => $member->id,
            'recipient_name' => 'Marie Durand',
            'recipient_email' => 'marie@fournisseur.example',
            'expires_at' => now()->addDays(7),
        ]);

        // As the upload of a guest leaves it (UploadsController): not public, tied to the request
        $share = (new Share())->forceFill([
            'user_id' => null,
            'invite_id' => $invite->id,
            'public' => false,
            'name' => 'Pièces du dossier',
            'path' => 'tests-'.Str::random(12),
            'long_id' => 'test-'.Str::lower(Str::random(16)),
            'size' => 10,
            'file_count' => 1,
            'download_count' => 0,
            'expires_at' => now()->addDays(5),
            'status' => 'ready',
        ]);
        $share->save();

        return $share;
    }

    private function readAs(User $user, Share $share)
    {
        $this->app['auth']->forgetGuards();

        return $this->withCredentials()
            ->withUnencryptedCookie('refresh_token', auth('api')->tokenById($user->id))
            ->getJson("/api/shares/{$share->long_id}");
    }

    private function member(): User
    {
        return User::factory()->create(['admin' => false, 'active' => true, 'must_change_password' => false, 'is_guest' => false]);
    }

    public function test_the_member_who_asked_reads_what_was_dropped(): void
    {
        $member = $this->member();

        $this->readAs($member, $this->droppedFor($member))->assertOk();
    }

    public function test_another_member_does_not(): void
    {
        $this->readAs($this->member(), $this->droppedFor($this->member()))->assertNotFound();
    }

    public function test_a_disabled_account_no_longer_reads_it(): void
    {
        $member = $this->member();
        $share = $this->droppedFor($member);
        $member->forceFill(['active' => false])->save();

        $this->readAs($member, $share)->assertNotFound();
    }

    public function test_a_share_whose_request_was_removed_is_not_found_rather_than_an_error(): void
    {
        $member = $this->member();
        $share = $this->droppedFor($member);
        ReverseShareInvite::whereKey($share->invite_id)->delete();

        $this->readAs($member, $share)->assertNotFound();
    }
}
