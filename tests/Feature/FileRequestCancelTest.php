<?php

namespace Tests\Feature;

use App\Models\ReverseShareInvite;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

// A member takes back a request they sent: its link stops working at once, the guest account
// behind it goes, and it leaves the list. A request that came back stays: its files are a share.
class FileRequestCancelTest extends TestCase
{
    use RefreshDatabase;

    protected $seed = true;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
    }

    private function member(): User
    {
        return User::factory()->create(['admin' => false, 'active' => true, 'must_change_password' => false, 'is_guest' => false]);
    }

    // Each request of a test reads its own token, as each request of a server does (the JWT
    // service is kept from one request to the next within a test)
    private function fresh(): void
    {
        $this->app['auth']->forgetGuards();
        $this->app->forgetInstance('tymon.jwt');
    }

    private function bearer(User $user): array
    {
        $this->fresh();

        return ['Authorization' => 'Bearer '.auth('api')->tokenById($user->id)];
    }

    // The request, and the token of its link
    private function ask(User $member): array
    {
        $link = $this->postJson('/api/reverse-shares/invite', [
            'delivery' => 'link',
            'recipient_name' => 'Dossier Martin',
        ], $this->bearer($member))->assertOk()->json('data.link');
        parse_str((string) parse_url($link, PHP_URL_QUERY), $query);

        return [ReverseShareInvite::latest('id')->first(), $query['invite_token']];
    }

    private function open(string $token)
    {
        $this->fresh();

        return $this->postJson('/api/reverse-shares/accept', ['token' => $token]);
    }

    private function cancel(User $member, ReverseShareInvite $invite)
    {
        return $this->deleteJson("/api/reverse-shares/invites/{$invite->id}", [], $this->bearer($member));
    }

    public function test_a_cancelled_request_no_longer_opens(): void
    {
        $member = $this->member();
        [$invite, $token] = $this->ask($member);
        $guestId = $invite->guest_user_id;

        $this->cancel($member, $invite)->assertOk();

        $this->assertNull(ReverseShareInvite::find($invite->id));
        $this->assertNull(User::find($guestId));
        $this->assertGreaterThanOrEqual(400, $this->open($token)->status());
    }

    public function test_a_guest_who_already_opened_the_link_is_let_go(): void
    {
        $member = $this->member();
        [$invite, $token] = $this->ask($member);
        $session = $this->open($token)->assertOk()->json('data.access_token');

        $this->cancel($member, $invite)->assertOk();

        $this->fresh();
        $this->getJson('/api/users/me', ['Authorization' => "Bearer {$session}"])->assertUnauthorized();
    }

    public function test_a_member_cannot_cancel_the_request_of_someone_else(): void
    {
        [$invite] = $this->ask($this->member());

        $this->cancel($this->member(), $invite)->assertNotFound();

        $this->assertNotNull(ReverseShareInvite::find($invite->id));
    }

    public function test_a_request_that_came_back_stays(): void
    {
        $member = $this->member();
        [$invite] = $this->ask($member);
        $invite->forceFill(['completed_at' => now()])->save();

        $this->cancel($member, $invite)->assertStatus(409);

        $this->assertNotNull(ReverseShareInvite::find($invite->id));
    }

    public function test_an_expired_request_leaves_the_list(): void
    {
        $member = $this->member();
        [$invite] = $this->ask($member);
        $invite->forceFill(['expires_at' => now()->subDay()])->save();

        $this->cancel($member, $invite)->assertOk();

        $this->assertSame([], $this->getJson('/api/reverse-shares/invites', $this->bearer($member))->json('data.invites'));
    }

    public function test_cancelling_needs_an_account(): void
    {
        [$invite] = $this->ask($this->member());
        $this->fresh();

        $this->deleteJson("/api/reverse-shares/invites/{$invite->id}")->assertUnauthorized();
        $this->assertNotNull(ReverseShareInvite::find($invite->id));
    }
}
