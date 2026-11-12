<?php

namespace Tests\Feature;

use App\Jobs\sendEmail;
use App\Models\ReverseShareInvite;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

// A file request gives the outside person a guest account whose token arrives by e-mail. That
// account drops files for the member who asked, nothing more: it never makes the server of the
// organisation write to other people, nor sets up a second factor or keys on itself.
class GuestBoundariesTest extends TestCase
{
    use RefreshDatabase;

    protected $seed = true;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
    }

    private function guest(): User
    {
        $member = User::factory()->create(['admin' => false, 'active' => true, 'must_change_password' => false, 'is_guest' => false]);
        $guest = User::factory()->create(['name' => 'Marie Durand', 'is_guest' => true, 'active' => true, 'must_change_password' => false]);
        ReverseShareInvite::create([
            'user_id' => $member->id,
            'guest_user_id' => $guest->id,
            'recipient_name' => 'Marie Durand',
            'recipient_email' => 'marie@fournisseur.example',
            'expires_at' => now()->addDays(7),
        ]);

        return $guest;
    }

    private function bearer(User $user): array
    {
        return ['Authorization' => 'Bearer '.auth('api')->tokenById($user->id)];
    }

    public function test_a_guest_cannot_send_file_requests_to_other_people(): void
    {
        $guest = $this->guest();

        $this->postJson('/api/reverse-shares/invite', [
            'recipient_name' => 'Comptabilité',
            'recipient_email' => 'compta@entreprise.example',
            'message' => 'Merci de déposer vos identifiants ici',
        ], $this->bearer($guest))->assertForbidden();

        Queue::assertNotPushed(sendEmail::class);
        $this->assertSame(1, ReverseShareInvite::count());
    }

    public function test_a_guest_cannot_set_up_a_second_factor_or_keys(): void
    {
        $guest = $this->guest();

        $this->postJson('/api/users/me/2fa/totp/setup', ['password' => 'password'], $this->bearer($guest))->assertForbidden();
        $this->postJson('/api/users/me/passkeys/options', [], $this->bearer($guest))->assertForbidden();
    }

    public function test_a_guest_cannot_change_the_account_it_was_given(): void
    {
        $guest = $this->guest();

        $this->putJson('/api/users/me', ['name' => 'Service informatique'], $this->bearer($guest))->assertForbidden();
        $this->postJson('/api/users/me/email', ['email' => 'it@entreprise.example', 'current_password' => 'password'], $this->bearer($guest))->assertForbidden();

        $this->assertSame('Marie Durand', $guest->fresh()->name);
        Queue::assertNotPushed(sendEmail::class);
    }

    public function test_a_member_still_sends_file_requests(): void
    {
        $member = User::factory()->create(['admin' => false, 'active' => true, 'must_change_password' => false, 'is_guest' => false]);

        $this->postJson('/api/reverse-shares/invite', [
            'recipient_name' => 'Marie Durand',
            'recipient_email' => 'marie@fournisseur.example',
            'message' => 'Les pièces du dossier',
        ], $this->bearer($member))->assertOk();

        Queue::assertPushed(sendEmail::class);
    }

    public function test_a_member_sends_twenty_requests_a_minute_at_most(): void
    {
        $member = User::factory()->create(['admin' => false, 'active' => true, 'must_change_password' => false, 'is_guest' => false]);

        for ($i = 0; $i < 20; $i++) {
            $this->postJson('/api/reverse-shares/invite', [
                'recipient_name' => "Contact {$i}",
                'recipient_email' => "contact{$i}@fournisseur.example",
            ], $this->bearer($member))->assertOk();
        }

        $this->postJson('/api/reverse-shares/invite', [
            'recipient_name' => 'Contact encore',
            'recipient_email' => 'encore@fournisseur.example',
        ], $this->bearer($member))->assertStatus(429);
    }

    public function test_the_message_of_a_request_has_a_length_limit(): void
    {
        $member = User::factory()->create(['admin' => false, 'active' => true, 'must_change_password' => false, 'is_guest' => false]);

        $this->postJson('/api/reverse-shares/invite', [
            'recipient_name' => 'Marie Durand',
            'recipient_email' => 'marie@fournisseur.example',
            'message' => str_repeat('a', 5001),
        ], $this->bearer($member))->assertUnprocessable();

        Queue::assertNotPushed(sendEmail::class);
    }
}
