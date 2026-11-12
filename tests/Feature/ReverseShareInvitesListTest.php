<?php

namespace Tests\Feature;

use App\Models\ReverseShareInvite;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

// The file requests page follows the requests a member sent: waiting, received or expired.
// A member only ever sees their own.
class ReverseShareInvitesListTest extends TestCase
{
    use RefreshDatabase;

    protected $seed = true;

    private function member(): User
    {
        return User::factory()->create(['admin' => false, 'active' => true, 'must_change_password' => false, 'is_guest' => false]);
    }

    private function invite(User $owner, array $attributes = []): ReverseShareInvite
    {
        return ReverseShareInvite::create(array_merge([
            'user_id' => $owner->id,
            'recipient_name' => 'Marie Durand',
            'recipient_email' => 'marie@example.test',
            'message' => 'Les pièces du dossier',
            'expires_at' => now()->addDays(7),
        ], $attributes));
    }

    public function test_a_member_follows_the_requests_they_sent(): void
    {
        $me = $this->member();
        $this->invite($me);
        $this->invite($me, ['recipient_name' => 'Thomas Leroy', 'completed_at' => now()]);
        $this->invite($me, ['recipient_name' => 'Julien Bernard', 'expires_at' => now()->subDay()]);

        $invites = $this->actingAs($me, 'api')->getJson('/api/reverse-shares/invites')->assertOk()->json('data.invites');

        $states = collect($invites)->pluck('state', 'recipient_name')->all();
        $this->assertSame(['Marie Durand' => 'pending', 'Thomas Leroy' => 'received', 'Julien Bernard' => 'expired'], $states);
    }

    public function test_a_member_never_sees_the_requests_of_someone_else(): void
    {
        $me = $this->member();
        $this->invite($this->member(), ['recipient_email' => 'secret@example.test']);

        $invites = $this->actingAs($me, 'api')->getJson('/api/reverse-shares/invites')->assertOk()->json('data.invites');

        $this->assertSame([], $invites);
    }

    public function test_the_list_does_not_expose_the_guest_account(): void
    {
        $me = $this->member();
        $guest = User::factory()->create(['is_guest' => true]);
        $this->invite($me, ['guest_user_id' => $guest->id]);

        $invite = $this->actingAs($me, 'api')->getJson('/api/reverse-shares/invites')->json('data.invites.0');

        $this->assertSame(['id', 'recipient_name', 'recipient_email', 'message', 'state', 'created_at', 'expires_at', 'completed_at'], array_keys($invite));
    }

    public function test_the_list_needs_an_account(): void
    {
        $this->getJson('/api/reverse-shares/invites')->assertUnauthorized();
    }
}
