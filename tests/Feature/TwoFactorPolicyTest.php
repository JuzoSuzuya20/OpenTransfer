<?php

namespace Tests\Feature;

use App\AuthProviders\AuthProviderUser;
use App\AuthProviders\TestingAuthProvider;
use App\Models\AuthProvider;
use App\Models\Setting;
use App\Models\User;
use App\Services\SettingsService;
use App\Services\Totp;
use Database\Seeders\SettingsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

require_once __DIR__.'/../Support/TestingAuthProvider.php';

/**
 * Admin policy on who must use a second factor. It covers password sign-in:
 * SSO accounts answer to the identity provider, and must keep working as is.
 */
class TwoFactorPolicyTest extends TestCase
{
    use RefreshDatabase;

    protected $seed = true;

    protected function tearDown(): void
    {
        TestingAuthProvider::$nextUser = null;
        parent::tearDown();
    }

    private function policy(string $value): void
    {
        Setting::where('key', 'mfa_required_for')->update(['value' => $value]);
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

    public function test_by_default_nothing_is_required(): void
    {
        $this->assertSame('none', Setting::where('key', 'mfa_required_for')->value('value'));

        $this->login($this->user(['admin' => true]))->assertOk()->assertJsonPath('status', 'success');
    }

    public function test_seeding_on_every_start_keeps_the_configured_policy(): void
    {
        $this->policy('everyone');

        $this->seed(SettingsSeeder::class);

        $this->assertSame('everyone', Setting::where('key', 'mfa_required_for')->value('value'));
    }

    public function test_the_admins_policy_only_targets_admins(): void
    {
        $this->policy('admins');

        $this->login($this->user(['admin' => true]))->assertOk()->assertJsonPath('status', 'mfa_enrolment_required');
        $this->login($this->user())->assertOk()->assertJsonPath('status', 'success');
    }

    public function test_the_everyone_policy_targets_members_too(): void
    {
        $this->policy('everyone');

        $this->login($this->user())
            ->assertOk()
            ->assertJsonPath('status', 'mfa_enrolment_required')
            ->assertJsonMissingPath('data.access_token')
            ->assertCookieMissing('refresh_token');
    }

    public function test_reverse_share_guests_are_never_targeted(): void
    {
        $this->policy('everyone');

        $this->login($this->user(['is_guest' => true]))->assertOk()->assertJsonPath('status', 'success');
    }

    public function test_setting_up_the_authenticator_opens_the_session(): void
    {
        $this->policy('everyone');
        $user = $this->user();
        $token = $this->login($user)->json('data.enrol_token');

        $setup = $this->fresh()->postJson('/api/auth/mfa/enrol/totp/setup', ['enrol_token' => $token])->assertOk()->json('data');

        $this->fresh()->postJson('/api/auth/mfa/enrol/totp/confirm', ['enrol_token' => $token, 'code' => '000000'])
            ->assertStatus(422)
            ->assertJson(['code' => 'mfa_invalid_code'])
            ->assertCookieMissing('refresh_token');

        $response = $this->fresh()->postJson('/api/auth/mfa/enrol/totp/confirm', [
            'enrol_token' => $token,
            'code' => Totp::codeAt($setup['secret'], Totp::timestep() - 1),
        ])->assertOk()->assertJsonStructure(['data' => ['access_token', 'recovery_codes']]);

        $response->assertPlainCookie('refresh_token');
        $this->assertCount(10, $response->json('data.recovery_codes'));

        $this->login($user)->assertOk()->assertJsonPath('status', 'mfa_required');
    }

    public function test_the_enrolment_token_works_only_once(): void
    {
        $this->policy('everyone');
        $token = $this->login($this->user())->json('data.enrol_token');
        $secret = $this->fresh()->postJson('/api/auth/mfa/enrol/totp/setup', ['enrol_token' => $token])->json('data.secret');
        $this->fresh()->postJson('/api/auth/mfa/enrol/totp/confirm', ['enrol_token' => $token, 'code' => Totp::codeAt($secret, Totp::timestep())])->assertOk();

        $this->fresh()->postJson('/api/auth/mfa/enrol/totp/setup', ['enrol_token' => $token])
            ->assertUnauthorized()
            ->assertJson(['code' => 'mfa_enrolment_expired']);
    }

    public function test_the_enrolment_token_expires(): void
    {
        $this->policy('everyone');
        $token = $this->login($this->user())->json('data.enrol_token');

        $this->travel(16)->minutes();

        $this->fresh()->postJson('/api/auth/mfa/enrol/totp/setup', ['enrol_token' => $token])
            ->assertUnauthorized()
            ->assertJson(['code' => 'mfa_enrolment_expired']);
    }

    public function test_the_enrolment_token_gives_no_access_to_the_api(): void
    {
        $this->policy('everyone');
        $token = $this->login($this->user())->json('data.enrol_token');

        $this->fresh()->getJson('/api/users/me', ['Authorization' => 'Bearer '.$token])->assertUnauthorized();
    }

    public function test_the_enrolment_token_of_someone_cannot_replace_an_existing_authenticator(): void
    {
        $this->policy('everyone');
        $user = $this->user();
        $token = $this->login($user)->json('data.enrol_token');
        $first = $this->fresh()->postJson('/api/users/me/2fa/totp/setup', ['password' => 'password'], $this->bearer($user))->json('data.secret');
        $this->fresh()->postJson('/api/users/me/2fa/totp/confirm', ['code' => Totp::codeAt($first, Totp::timestep() - 1)], $this->bearer($user))->assertOk();

        $this->fresh()->postJson('/api/auth/mfa/enrol/totp/setup', ['enrol_token' => $token])
            ->assertUnauthorized()
            ->assertJson(['code' => 'mfa_enrolment_expired']);
    }

    public function test_the_last_factor_cannot_be_removed_while_it_is_required(): void
    {
        $this->policy('everyone');
        $user = $this->user();
        $secret = $this->fresh()->postJson('/api/users/me/2fa/totp/setup', ['password' => 'password'], $this->bearer($user))->json('data.secret');
        $this->fresh()->postJson('/api/users/me/2fa/totp/confirm', ['code' => Totp::codeAt($secret, Totp::timestep())], $this->bearer($user))->assertOk();

        $this->fresh()->deleteJson('/api/users/me/2fa/totp', ['password' => 'password'], $this->bearer($user))
            ->assertStatus(422)
            ->assertJson(['code' => 'mfa_required_by_policy']);

        $this->assertNotNull($user->fresh()->totp_enabled_at);
    }

    public function test_the_status_tells_when_it_is_required(): void
    {
        $this->policy('everyone');

        $this->getJson('/api/users/me/2fa', $this->bearer($this->user()))->assertOk()->assertJsonPath('data.required', true);
    }

    public function test_password_sessions_are_not_renewed_without_a_second_factor(): void
    {
        $user = $this->user();
        $refreshToken = $this->login($user)->getCookie('refresh_token', false)->getValue();
        $this->policy('everyone');

        $this->refresh($refreshToken)
            ->assertUnauthorized()
            ->assertJson(['code' => 'mfa_enrolment_required'])
            ->assertJsonMissingPath('data.access_token');
    }

    public function test_password_sessions_are_renewed_once_a_second_factor_is_set_up(): void
    {
        $user = $this->user();
        $refreshToken = $this->login($user)->getCookie('refresh_token', false)->getValue();
        $secret = $this->fresh()->postJson('/api/users/me/2fa/totp/setup', ['password' => 'password'], $this->bearer($user))->json('data.secret');
        $this->fresh()->postJson('/api/users/me/2fa/totp/confirm', ['code' => Totp::codeAt($secret, Totp::timestep())], $this->bearer($user))->assertOk();
        $this->policy('everyone');

        $this->refresh($refreshToken)->assertOk()->assertJsonStructure(['data' => ['access_token']]);
    }

    /**
     * The policy must not touch SSO: these accounts have no password to protect
     * and every hourly renewal goes through this route.
     */
    public function test_sso_sign_in_and_its_renewals_are_not_affected_by_the_policy(): void
    {
        $this->policy('everyone');
        $provider = new AuthProvider();
        $provider->forceFill([
            'name' => 'Test IdP', 'provider_class' => 'Testing', 'provider_config' => [], 'enabled' => true,
            'uuid' => (string) Str::uuid(), 'allow_registration' => false, 'trust_email' => false,
        ])->save();
        $user = $this->user();
        $user->authProviders()->attach($provider->id, ['provider_user_id' => 'sub-1', 'provider_email' => $user->email]);
        TestingAuthProvider::$nextUser = new AuthProviderUser([
            'sub' => 'sub-1', 'name' => 'Jane Doe', 'email' => $user->email, 'avatar' => null, 'verified' => true,
        ]);

        $cookie = $this->get("/auth/provider/{$provider->uuid}/callback")->getCookie('refresh_token', false)->getValue();

        $renewed = $this->refresh(urldecode($cookie))->assertOk();

        // And the renewed session is still an SSO one, for the renewal after that
        $this->refresh($renewed->getCookie('refresh_token', false)->getValue())->assertOk();
    }

    public function test_a_session_from_the_second_factor_keeps_being_renewed(): void
    {
        $user = $this->user();
        $secret = $this->fresh()->postJson('/api/users/me/2fa/totp/setup', ['password' => 'password'], $this->bearer($user))->json('data.secret');
        $this->fresh()->postJson('/api/users/me/2fa/totp/confirm', ['code' => Totp::codeAt($secret, Totp::timestep() - 1)], $this->bearer($user))->assertOk();
        $this->policy('everyone');
        $token = $this->login($user)->json('data.mfa_token');

        $session = $this->fresh()->postJson('/api/auth/mfa/verify', ['mfa_token' => $token, 'method' => 'totp', 'code' => Totp::codeAt($secret, Totp::timestep())])->assertOk();

        $this->refresh($session->getCookie('refresh_token', false)->getValue())->assertOk();
    }

    public function test_a_password_reset_opens_no_session_when_the_policy_requires_a_second_factor(): void
    {
        $this->policy('everyone');
        $user = $this->user();

        $this->postJson('/api/auth/reset-password', [
            'token' => Password::broker()->createToken($user),
            'email' => $user->email,
            'password' => 'a-brand-new-password',
            'password_confirmation' => 'a-brand-new-password',
        ])->assertOk()->assertCookieMissing('refresh_token');
    }

    public function test_only_known_policies_can_be_saved(): void
    {
        $admin = $this->user(['admin' => true]);

        $this->putJson('/api/settings', ['settings' => [['key' => 'mfa_required_for', 'value' => 'nobody']]], $this->bearer($admin))
            ->assertStatus(422)
            ->assertJson(['code' => 'mfa_policy_invalid']);
        $this->assertSame('none', Setting::where('key', 'mfa_required_for')->value('value'));

        $this->fresh()->putJson('/api/settings', ['settings' => [['key' => 'mfa_required_for', 'value' => 'admins']]], $this->bearer($admin))->assertOk();
        $this->assertSame('admins', Setting::where('key', 'mfa_required_for')->value('value'));
    }

    public function test_the_policy_is_hidden_from_members(): void
    {
        $this->getJson('/api/settings/mfa_required_for', $this->bearer($this->user()))->assertNotFound();
    }
}
