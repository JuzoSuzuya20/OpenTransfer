<?php

namespace Tests\Feature;

use App\Jobs\sendEmail;
use App\Mail\emailVerificationMail;
use App\Mail\passwordResetMail;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

// The forms open to anyone send e-mails: asked again and again, they would fill a person's
// mailbox with messages from the organisation, and spend the reputation of its mail server.
// One message a minute per address, and a limit per network address.
class MailFloodTest extends TestCase
{
    use RefreshDatabase;

    protected $seed = true;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
    }

    private function pushed(string $mailable): int
    {
        return Queue::pushed(sendEmail::class, fn (sendEmail $job) => $job->mailableClass === $mailable)->count();
    }

    public function test_a_password_reset_is_sent_at_most_once_a_minute_to_an_address(): void
    {
        $user = User::factory()->create(['email' => 'camille@entreprise.example', 'admin' => false, 'active' => true, 'is_guest' => false]);

        for ($i = 0; $i < 4; $i++) {
            $this->postJson('/api/auth/forgot-password', ['email' => $user->email])->assertOk();
        }
        $this->assertSame(1, $this->pushed(passwordResetMail::class));

        $this->travel(61)->seconds();
        $this->postJson('/api/auth/forgot-password', ['email' => $user->email])->assertOk();
        $this->assertSame(2, $this->pushed(passwordResetMail::class));
    }

    public function test_a_verification_code_is_sent_again_at_most_once_a_minute(): void
    {
        $user = User::factory()->create(['email' => 'nouveau@entreprise.example', 'active' => false, 'is_guest' => false]);
        $user->forceFill(['email_verification_code' => '123456', 'email_verification_code_expires_at' => now()->addMinutes(15)])->save();

        for ($i = 0; $i < 4; $i++) {
            $this->postJson('/api/auth/resend-verification', ['email' => $user->email])->assertOk();
        }
        $this->assertSame(0, $this->pushed(emailVerificationMail::class), 'the code that just left is still the one to use');

        $this->travel(61)->seconds();
        $this->postJson('/api/auth/resend-verification', ['email' => $user->email])->assertOk();
        $this->assertSame(1, $this->pushed(emailVerificationMail::class));
    }

    public function test_one_network_address_cannot_ask_for_e_mails_without_end(): void
    {
        for ($i = 0; $i < 10; $i++) {
            $this->postJson('/api/auth/forgot-password', ['email' => "personne{$i}@entreprise.example"])->assertOk();
        }

        $this->postJson('/api/auth/forgot-password', ['email' => 'encore@entreprise.example'])->assertStatus(429);
        $this->postJson('/api/auth/resend-verification', ['email' => 'encore@entreprise.example'])->assertStatus(429);
    }
}
