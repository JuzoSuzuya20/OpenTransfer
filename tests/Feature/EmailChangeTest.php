<?php

namespace Tests\Feature;

use App\Jobs\sendEmail;
use App\Mail\emailChangeMail;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

// A member changes the address of their account only with a code sent to the new address. Without
// it anyone could take the address of a colleague before their first SSO sign-in: the identity
// provider, trusting addresses, would then open the colleague's session in the account of the
// person who took it, who keeps its password.
class EmailChangeTest extends TestCase
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
        return User::factory()->create([
            'email' => 'camille@partenaire.example',
            'admin' => false,
            'active' => true,
            'must_change_password' => false,
            'is_guest' => false,
        ]);
    }

    private function bearer(User $user): array
    {
        return ['Authorization' => 'Bearer '.auth('api')->tokenById($user->id)];
    }

    private function askFor(User $user, string $email, ?string $password = 'password')
    {
        $this->app['auth']->forgetGuards();

        return $this->postJson('/api/users/me/email', array_filter(['email' => $email, 'current_password' => $password]), $this->bearer($user));
    }

    private function confirm(User $user, string $code)
    {
        $this->app['auth']->forgetGuards();

        return $this->postJson('/api/users/me/email/confirm', ['code' => $code], $this->bearer($user));
    }

    private function codeSentTo(string $email): string
    {
        $code = null;
        Queue::assertPushed(sendEmail::class, function (sendEmail $job) use ($email, &$code) {
            if ($job->to === $email && $job->mailableClass === emailChangeMail::class) {
                $code = $job->mailableData['code'];

                return true;
            }

            return false;
        });

        return $code;
    }

    public function test_the_address_no_longer_changes_with_the_rest_of_the_profile(): void
    {
        $user = $this->member();

        $this->putJson('/api/users/me', ['email' => 'dominique@entreprise.example', 'name' => 'Camille'], $this->bearer($user))
            ->assertStatus(409)
            ->assertJsonPath('code', 'email_change_needs_code');

        $this->assertSame('camille@partenaire.example', $user->fresh()->email);
        $this->assertNotSame('Camille', $user->fresh()->name);
    }

    public function test_the_same_address_with_the_rest_of_the_profile_still_saves(): void
    {
        $user = $this->member();

        $this->putJson('/api/users/me', ['email' => 'Camille@partenaire.example', 'name' => 'Camille'], $this->bearer($user))->assertOk();

        $this->assertSame('Camille', $user->fresh()->name);
    }

    public function test_the_address_changes_once_the_code_sent_to_it_comes_back(): void
    {
        $user = $this->member();

        $this->askFor($user, 'camille@entreprise.example')->assertOk()->assertJsonPath('data.pending_email', 'camille@entreprise.example');
        // Nothing changes until the code comes back
        $this->assertSame('camille@partenaire.example', $user->fresh()->email);

        $code = $this->codeSentTo('camille@entreprise.example');
        $this->assertMatchesRegularExpression('/^\d{6}$/', $code);
        $this->assertStringNotContainsString($code, (string) $user->fresh()->pending_email_code, 'the code is kept hashed');

        $this->confirm($user, $code)->assertOk()->assertJsonPath('data.user.email', 'camille@entreprise.example');

        $user->refresh();
        $this->assertSame('camille@entreprise.example', $user->email);
        $this->assertNull($user->pending_email);
        $this->assertNull($user->pending_email_code);

        // A code works once
        $this->confirm($user, $code)->assertUnprocessable();
    }

    public function test_the_current_password_is_asked_first(): void
    {
        $user = $this->member();

        $this->askFor($user, 'camille@entreprise.example', null)->assertUnprocessable();
        $this->askFor($user, 'camille@entreprise.example', 'not-the-password')->assertUnprocessable();

        Queue::assertNotPushed(sendEmail::class);
        $this->assertNull($user->fresh()->pending_email);
    }

    public function test_an_address_already_used_by_an_account_is_refused(): void
    {
        $user = $this->member();
        User::factory()->create(['email' => 'dominique@entreprise.example']);

        $this->askFor($user, 'Dominique@entreprise.example')->assertUnprocessable();

        Queue::assertNotPushed(sendEmail::class);
    }

    public function test_an_address_taken_in_the_meantime_is_refused_at_confirmation(): void
    {
        $user = $this->member();
        $this->askFor($user, 'camille@entreprise.example')->assertOk();
        $code = $this->codeSentTo('camille@entreprise.example');

        User::factory()->create(['email' => 'camille@entreprise.example']);

        $this->confirm($user, $code)->assertStatus(409);
        $this->assertSame('camille@partenaire.example', $user->fresh()->email);
    }

    public function test_a_wrong_code_changes_nothing_and_guessing_is_limited(): void
    {
        $user = $this->member();
        $this->askFor($user, 'camille@entreprise.example')->assertOk();
        $code = $this->codeSentTo('camille@entreprise.example');
        $wrong = $code === '000000' ? '111111' : '000000';

        for ($attempt = 0; $attempt < 5; $attempt++) {
            $this->confirm($user, $wrong)->assertUnprocessable();
        }

        $this->confirm($user, $code)->assertStatus(429);
        $this->assertSame('camille@partenaire.example', $user->fresh()->email);
    }

    public function test_a_code_older_than_a_quarter_of_an_hour_changes_nothing(): void
    {
        $user = $this->member();
        $this->askFor($user, 'camille@entreprise.example')->assertOk();
        $code = $this->codeSentTo('camille@entreprise.example');

        $this->travel(16)->minutes();

        $this->confirm($user, $code)->assertUnprocessable();
        $this->assertSame('camille@partenaire.example', $user->fresh()->email);
    }

    public function test_an_account_of_the_organisation_keeps_the_address_of_its_identity_provider(): void
    {
        \App\Models\Setting::where('key', 'sso_required_domains')->update(['value' => 'partenaire.example']);
        app(\App\Services\SettingsService::class)->clearCache();
        $user = $this->member();

        $this->askFor($user, 'camille@ailleurs.example')->assertForbidden()->assertJsonPath('code', 'sso_required');

        Queue::assertNotPushed(sendEmail::class);
    }

    public function test_an_admin_still_sets_the_address_of_an_account(): void
    {
        $admin = User::factory()->create(['admin' => true, 'active' => true, 'must_change_password' => false, 'is_guest' => false]);
        $user = $this->member();

        $this->putJson("/api/users/{$user->id}", ['email' => 'camille@entreprise.example', 'name' => $user->name], $this->bearer($admin))->assertOk();

        $this->assertSame('camille@entreprise.example', $user->fresh()->email);
    }
}
