<?php

namespace Tests\Feature;

use App\AuthProviders\AuthProviderUser;
use App\AuthProviders\TestingAuthProvider;
use App\Models\AuthProvider;
use App\Models\Setting;
use App\Models\User;
use App\Services\SettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * A forced identity provider (Administration > SSO, like the "forced" OpenID Connect of ITSM-NG):
 * everyone signs in through it, whatever the domain of their address. Only admins get past it, at
 * /?noAUTO, and with a second factor, as the admins of a domain that requires SSO: that door
 * stays to repair a broken identity provider, not to keep a password. Reverse share guests are not
 * concerned. A disabled provider forces nothing, so that nobody is locked out.
 */
class ForcedSsoAccessTest extends TestCase
{
    use RefreshDatabase;

    protected $seed = true;

    protected function tearDown(): void
    {
        TestingAuthProvider::$nextUser = null;
        parent::tearDown();
    }

    private function provider(bool $enabled = true): AuthProvider
    {
        $provider = new AuthProvider();
        $provider->forceFill([
            'name' => 'Keycloak', 'provider_class' => 'Testing', 'provider_config' => [], 'enabled' => $enabled,
            'uuid' => (string) Str::uuid(), 'allow_registration' => false, 'trust_email' => true,
        ])->save();

        return $provider;
    }

    private function force(AuthProvider $provider): void
    {
        Setting::where('key', 'sso_forced_provider')->update(['value' => (string) $provider->id]);
        app(SettingsService::class)->clearCache();
    }

    private function user(array $attributes = []): User
    {
        return User::factory()->create(array_merge(['admin' => false, 'active' => true, 'is_guest' => false, 'must_change_password' => false], $attributes));
    }

    private function login(User $user): TestResponse
    {
        $this->app['auth']->forgetGuards();
        $this->app->forgetInstance('tymon.jwt');

        return $this->postJson('/api/auth/login', ['email' => $user->email, 'password' => 'password']);
    }

    public function test_a_member_cannot_sign_in_with_a_password_while_a_provider_is_forced(): void
    {
        $this->force($this->provider());

        // Whatever the domain of the address: the provider is the way in for everyone
        $this->login($this->user(['email' => 'camille@partenaire.example']))
            ->assertForbidden()
            ->assertJsonPath('code', 'sso_required');
    }

    public function test_an_admin_gets_past_it_with_a_second_factor(): void
    {
        $this->force($this->provider());

        $this->login($this->user(['email' => 'admin@ville.example', 'admin' => true]))
            ->assertOk()
            ->assertJsonPath('status', 'mfa_enrolment_required');
    }

    public function test_the_forced_provider_still_signs_members_in(): void
    {
        $provider = $this->provider();
        $this->force($provider);
        $member = $this->user(['email' => 'camille@ville.example']);
        TestingAuthProvider::$nextUser = new AuthProviderUser(['sub' => 'kc-123', 'name' => 'Camille', 'email' => $member->email, 'avatar' => null, 'verified' => true]);

        $this->get("/auth/provider/{$provider->uuid}/callback")->assertRedirect();

        $this->assertDatabaseHas('user_auth_provider', ['user_id' => $member->id, 'auth_provider_id' => $provider->id]);
    }

    public function test_no_reset_link_is_sent_to_a_member(): void
    {
        Queue::fake();
        $this->force($this->provider());
        $this->user(['email' => 'camille@ville.example']);

        // The usual answer: it must not tell members from admins
        $this->postJson('/api/auth/forgot-password', ['email' => 'camille@ville.example'])
            ->assertOk()
            ->assertJsonPath('message', 'Password reset email sent');

        Queue::assertNothingPushed();
    }

    public function test_an_admin_can_still_reset_the_password(): void
    {
        Queue::fake();
        $this->force($this->provider());
        $this->user(['email' => 'admin@ville.example', 'admin' => true]);

        $this->postJson('/api/auth/forgot-password', ['email' => 'admin@ville.example'])->assertOk();

        Queue::assertPushed(\App\Jobs\sendEmail::class);
    }

    public function test_nobody_signs_up_while_a_provider_is_forced(): void
    {
        Setting::where('key', 'self_registration_enabled')->update(['value' => 'true']);
        $this->force($this->provider());

        $this->postJson('/api/auth/register', [
            'name' => 'Camille', 'email' => 'camille@partenaire.example',
            'password' => 'a-brand-new-password', 'password_confirmation' => 'a-brand-new-password',
        ])->assertForbidden()->assertJsonPath('code', 'sso_required');

        $this->assertDatabaseMissing('users', ['email' => 'camille@partenaire.example']);
    }

    public function test_a_disabled_provider_forces_nothing_and_locks_nobody_out(): void
    {
        $this->force($this->provider(enabled: false));

        $this->login($this->user(['email' => 'camille@ville.example']))->assertOk()->assertJsonPath('status', 'success');
    }

    public function test_reverse_share_guests_are_not_concerned(): void
    {
        $this->force($this->provider());

        $this->assertFalse(app(\App\Services\SsoPolicy::class)->forbidsPassword($this->user(['is_guest' => true])));
    }
}
