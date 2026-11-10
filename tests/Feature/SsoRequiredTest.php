<?php

namespace Tests\Feature;

use App\AuthProviders\AuthProviderUser;
use App\AuthProviders\TestingAuthProvider;
use App\Models\AuthProvider;
use App\Models\Setting;
use App\Models\User;
use App\Services\SettingsService;
use App\Services\Totp;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

require_once __DIR__.'/../Support/TestingAuthProvider.php';

/**
 * The accounts of the organisation sign in through its identity provider: an address in one of the
 * domains the admin lists cannot open a session with a password, a reset link, a sign-up or a
 * passkey alone. Admins keep their password, with a second factor, to repair a broken SSO.
 */
class SsoRequiredTest extends TestCase
{
    use RefreshDatabase;

    protected $seed = true;

    protected function tearDown(): void
    {
        TestingAuthProvider::$nextUser = null;
        parent::tearDown();
    }

    private function requireSsoFor(string $domains): void
    {
        Setting::where('key', 'sso_required_domains')->update(['value' => $domains]);
        app(SettingsService::class)->clearCache();
    }

    private function user(array $attributes = []): User
    {
        return User::factory()->create(array_merge(['admin' => false, 'active' => true, 'is_guest' => false], $attributes));
    }

    private function fresh(): static
    {
        $this->app['auth']->forgetGuards();
        $this->app->forgetInstance('tymon.jwt');

        return $this;
    }

    private function bearer(User $user): array
    {
        return ['Authorization' => 'Bearer '.auth('api')->tokenById($user->id)];
    }

    private function login(User $user): TestResponse
    {
        return $this->fresh()->postJson('/api/auth/login', ['email' => $user->email, 'password' => 'password']);
    }

    private function refresh(string $refreshToken): TestResponse
    {
        return $this->fresh()->withCredentials()
            ->withUnencryptedCookie('refresh_token', $refreshToken)
            ->postJson('/api/auth/refresh');
    }

    private function provider(): AuthProvider
    {
        $provider = new AuthProvider();
        $provider->forceFill([
            'name' => 'Test IdP', 'provider_class' => 'Testing', 'provider_config' => [], 'enabled' => true,
            'uuid' => (string) Str::uuid(), 'allow_registration' => false, 'trust_email' => true,
        ])->save();

        return $provider;
    }

    public function test_by_default_no_domain_is_required(): void
    {
        $this->assertSame('', Setting::where('key', 'sso_required_domains')->value('value'));

        $this->login($this->user(['email' => 'camille@entreprise.fr']))->assertOk();
    }

    public function test_an_account_of_a_required_domain_cannot_sign_in_with_a_password(): void
    {
        $this->requireSsoFor('entreprise.fr');

        $this->login($this->user(['email' => 'camille@entreprise.fr']))
            ->assertForbidden()
            ->assertJsonPath('code', 'sso_required')
            ->assertCookieMissing('refresh_token');
    }

    public function test_a_wrong_password_on_a_required_domain_says_nothing_more(): void
    {
        $this->requireSsoFor('entreprise.fr');
        $user = $this->user(['email' => 'camille@entreprise.fr']);

        // The password is checked first: the answer reveals no account
        $this->fresh()->postJson('/api/auth/login', ['email' => $user->email, 'password' => 'wrong'])->assertUnauthorized();
    }

    public function test_domains_are_matched_whatever_their_case_and_spacing(): void
    {
        $this->requireSsoFor(" @Entreprise.FR,\n mairie.fr ");

        $this->login($this->user(['email' => 'Camille@ENTREPRISE.fr']))->assertForbidden();
        $this->login($this->user(['email' => 'dominique@mairie.fr']))->assertForbidden();
    }

    public function test_other_domains_keep_their_password(): void
    {
        $this->requireSsoFor('entreprise.fr');

        $this->login($this->user(['email' => 'partenaire@bureau-etudes.fr']))->assertOk();
        // A domain that only ends like a required one is another domain
        $this->login($this->user(['email' => 'x@fausse-entreprise.fr']))->assertOk();
        $this->login($this->user(['email' => 'y@sous.entreprise.fr']))->assertOk();
    }

    public function test_an_admin_of_a_required_domain_keeps_the_password_but_must_set_up_a_second_factor(): void
    {
        $this->requireSsoFor('entreprise.fr');

        $this->login($this->user(['email' => 'admin@entreprise.fr', 'admin' => true]))
            ->assertOk()
            ->assertJsonPath('status', 'mfa_enrolment_required');
    }

    public function test_an_admin_with_a_second_factor_signs_in_with_it(): void
    {
        $this->requireSsoFor('entreprise.fr');
        $admin = $this->user(['email' => 'admin@entreprise.fr', 'admin' => true]);
        $secret = $this->fresh()->postJson('/api/users/me/2fa/totp/setup', ['password' => 'password'], $this->bearer($admin))->json('data.secret');
        $this->fresh()->postJson('/api/users/me/2fa/totp/confirm', ['code' => Totp::codeAt($secret, Totp::timestep() - 1)], $this->bearer($admin))->assertOk();

        $this->login($admin)->assertOk()->assertJsonPath('status', 'mfa_required');
    }

    public function test_the_identity_provider_still_signs_them_in(): void
    {
        $this->requireSsoFor('entreprise.fr');
        $provider = $this->provider();
        $user = $this->user(['email' => 'camille@entreprise.fr']);
        TestingAuthProvider::$nextUser = new AuthProviderUser([
            'sub' => 'sub-1', 'name' => 'Camille Martin', 'email' => $user->email, 'avatar' => null, 'verified' => true,
        ]);

        $cookie = $this->get("/auth/provider/{$provider->uuid}/callback")->assertRedirect('/')->getCookie('refresh_token', false);
        $this->assertNotNull($cookie);

        $this->refresh(urldecode($cookie->getValue()))->assertOk();
    }

    public function test_a_password_session_opened_before_the_rule_is_not_renewed(): void
    {
        $user = $this->user(['email' => 'camille@entreprise.fr']);
        $session = $this->login($user)->assertOk();
        $this->requireSsoFor('entreprise.fr');

        $this->refresh($session->getCookie('refresh_token', false)->getValue())
            ->assertUnauthorized()
            ->assertJsonPath('code', 'sso_required');
    }

    public function test_no_reset_link_is_sent_to_a_member_of_a_required_domain(): void
    {
        Queue::fake();
        $this->requireSsoFor('entreprise.fr');
        $this->user(['email' => 'camille@entreprise.fr']);

        // The answer is the usual one: it must not tell members from admins
        $this->postJson('/api/auth/forgot-password', ['email' => 'camille@entreprise.fr'])
            ->assertOk()
            ->assertJsonPath('message', 'Password reset email sent');

        Queue::assertNothingPushed();
    }

    public function test_an_admin_of_a_required_domain_can_still_reset_the_password(): void
    {
        Queue::fake();
        $this->requireSsoFor('entreprise.fr');
        $this->user(['email' => 'admin@entreprise.fr', 'admin' => true]);

        $this->postJson('/api/auth/forgot-password', ['email' => 'admin@entreprise.fr'])->assertOk();

        Queue::assertPushed(\App\Jobs\sendEmail::class);
    }

    public function test_a_made_up_reset_token_gets_the_usual_refusal(): void
    {
        $this->requireSsoFor('entreprise.fr');
        $user = $this->user(['email' => 'camille@entreprise.fr']);

        $this->postJson('/api/auth/reset-password', [
            'token' => 'made-up', 'email' => $user->email,
            'password' => 'a-brand-new-password', 'password_confirmation' => 'a-brand-new-password',
        ])->assertStatus(400);
    }

    public function test_a_reset_link_sent_before_the_rule_no_longer_works(): void
    {
        $user = $this->user(['email' => 'camille@entreprise.fr']);
        $token = Password::broker()->createToken($user);
        $this->requireSsoFor('entreprise.fr');

        $this->postJson('/api/auth/reset-password', [
            'token' => $token, 'email' => $user->email,
            'password' => 'a-brand-new-password', 'password_confirmation' => 'a-brand-new-password',
        ])->assertForbidden()->assertJsonPath('code', 'sso_required');

        $this->assertTrue(password_verify('password', $user->fresh()->password));
    }

    public function test_nobody_signs_up_with_an_address_of_a_required_domain(): void
    {
        Setting::where('key', 'self_registration_enabled')->update(['value' => 'true']);
        $this->requireSsoFor('entreprise.fr');

        $this->postJson('/api/auth/register', [
            'name' => 'Camille', 'email' => 'camille@entreprise.fr',
            'password' => 'a-brand-new-password', 'password_confirmation' => 'a-brand-new-password',
        ])->assertForbidden()->assertJsonPath('code', 'sso_required');

        $this->assertDatabaseMissing('users', ['email' => 'camille@entreprise.fr']);
    }

    public function test_an_account_of_a_required_domain_cannot_leave_it_by_changing_its_address(): void
    {
        $this->requireSsoFor('entreprise.fr');
        $user = $this->user(['email' => 'camille@entreprise.fr']);

        $this->fresh()->putJson('/api/users/me', ['email' => 'camille@gmail.example'], $this->bearer($user))
            ->assertForbidden()
            ->assertJsonPath('code', 'sso_required');

        $this->assertSame('camille@entreprise.fr', $user->fresh()->email);
    }

    public function test_reverse_share_guests_are_not_concerned(): void
    {
        $this->requireSsoFor('entreprise.fr');
        $guest = $this->user(['email' => 'invite@entreprise.fr', 'is_guest' => true]);

        $this->assertFalse(app(\App\Services\SsoPolicy::class)->forbidsPassword($guest));
    }

    public function test_the_page_tells_the_sign_in_screen_whether_a_domain_requires_sso(): void
    {
        $this->requireSsoFor('entreprise.fr');

        $this->get('/')->assertOk()->assertSee('sso_required_domains', false);
    }

    public function test_only_valid_domains_can_be_saved(): void
    {
        $admin = $this->user(['admin' => true]);

        $this->fresh()->putJson('/api/settings', ['settings' => [['key' => 'sso_required_domains', 'value' => 'entreprise.fr, not a domain']]], $this->bearer($admin))
            ->assertStatus(422)
            ->assertJsonPath('code', 'sso_domains_invalid');
    }
}
