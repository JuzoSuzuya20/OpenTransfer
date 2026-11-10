<?php

namespace Tests\Feature;

use App\Jobs\maintainDb;
use App\Models\Setting;
use App\Models\User;
use App\Services\SettingsService;
use App\Services\Totp;
use Database\Seeders\SettingsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Tests\Support\SoftwareAuthenticator;
use Tests\TestCase;

/**
 * Passkeys (WebAuthn), adapted from the moutonnoireu/erugo-security fork. The
 * software authenticator signs for real: the whole verification runs.
 */
class PasskeysTest extends TestCase
{
    use RefreshDatabase;

    protected $seed = true;

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

    private function registrationOptions(User $user, string $password = 'password'): TestResponse
    {
        return $this->fresh()->postJson('/api/users/me/passkeys/options', ['password' => $password], $this->bearer($user));
    }

    private function sendRegistration(User $user, array $credential, string $name = 'Office laptop'): TestResponse
    {
        return $this->fresh()->postJson('/api/users/me/passkeys', ['name' => $name, 'credential' => $credential], $this->bearer($user));
    }

    private function addPasskey(User $user, ?SoftwareAuthenticator $authenticator = null): SoftwareAuthenticator
    {
        $authenticator ??= new SoftwareAuthenticator();
        $options = $this->registrationOptions($user)->assertOk()->json('data.options');
        $this->sendRegistration($user, $authenticator->register($options))->assertOk();

        return $authenticator;
    }

    /** @return array{0: string, 1: array} the challenge token and the options for the browser */
    private function secondFactorChallenge(User $user): array
    {
        $token = $this->login($user)->assertOk()->assertJsonPath('status', 'mfa_required')->json('data.mfa_token');
        $options = $this->fresh()->postJson('/api/auth/mfa/passkey/options', ['mfa_token' => $token])->assertOk()->json('data.options');

        return [$token, $options];
    }

    private function sendAssertion(string $token, array $credential): TestResponse
    {
        return $this->fresh()->postJson('/api/auth/mfa/passkey', ['mfa_token' => $token, 'credential' => $credential]);
    }

    private function enablePasswordless(): void
    {
        Setting::where('key', 'passkey_login_enabled')->update(['value' => 'true']);
        app(SettingsService::class)->clearCache();
    }

    private function policy(string $value): void
    {
        Setting::where('key', 'mfa_required_for')->update(['value' => $value]);
        app(SettingsService::class)->clearCache();
    }

    // --- Adding one from the profile ---

    public function test_adding_a_passkey_needs_the_password(): void
    {
        $user = $this->user();

        $this->registrationOptions($user, 'wrong')
            ->assertStatus(422)
            ->assertJson(['code' => 'invalid_password'])
            ->assertJsonMissingPath('data.options');

        // No registration without options asked with the right password
        $this->sendRegistration($user, (new SoftwareAuthenticator())->register(['challenge' => 'AAAA']))->assertStatus(422);
        $this->assertSame(0, $user->passkeys()->count());
    }

    public function test_a_registration_is_stored_with_its_name_and_gives_recovery_codes(): void
    {
        $user = $this->user();
        $authenticator = new SoftwareAuthenticator();
        $options = $this->registrationOptions($user)->assertOk()->json('data.options');

        $this->assertSame('localhost', $options['rp']['id']);

        $codes = $this->sendRegistration($user, $authenticator->register($options))
            ->assertOk()
            ->assertJsonPath('data.passkey.name', 'Office laptop')
            ->json('data.recovery_codes');

        $this->assertCount(10, $codes);
        $this->assertSame(1, $user->passkeys()->count());
    }

    public function test_a_second_passkey_gives_no_new_recovery_codes(): void
    {
        $user = $this->user();
        $this->addPasskey($user);
        $options = $this->registrationOptions($user)->json('data.options');

        $this->sendRegistration($user, (new SoftwareAuthenticator())->register($options))
            ->assertOk()
            ->assertJsonPath('data.recovery_codes', null);
    }

    public function test_a_registration_answering_another_challenge_is_refused(): void
    {
        $user = $this->user();
        $this->registrationOptions($user)->assertOk();

        $this->sendRegistration($user, (new SoftwareAuthenticator())->register(['challenge' => 'b3RoZXItY2hhbGxlbmdl']))
            ->assertStatus(422)
            ->assertJson(['code' => 'passkey_registration_failed']);

        $this->assertSame(0, $user->passkeys()->count());
    }

    public function test_a_registration_from_another_site_is_refused(): void
    {
        $user = $this->user();
        $options = $this->registrationOptions($user)->json('data.options');

        $this->sendRegistration($user, (new SoftwareAuthenticator('localhost', 'https://evil.example'))->register($options))
            ->assertStatus(422);

        $options = $this->registrationOptions($user)->json('data.options');
        $this->sendRegistration($user, (new SoftwareAuthenticator('evil.example', 'http://localhost'))->register($options))
            ->assertStatus(422);

        $this->assertSame(0, $user->passkeys()->count());
    }

    public function test_options_are_bound_to_the_host_and_https_is_required_outside_localhost(): void
    {
        $user = $this->user();

        $options = $this->fresh()->postJson('https://share.example.test/api/users/me/passkeys/options', ['password' => 'password'], $this->bearer($user))
            ->json('data.options');
        $this->assertSame('share.example.test', $options['rp']['id']);

        $plainHttp = new SoftwareAuthenticator('share.example.test', 'http://share.example.test');
        $this->fresh()->postJson('https://share.example.test/api/users/me/passkeys', ['credential' => $plainHttp->register($options)], $this->bearer($user))
            ->assertStatus(422);

        $options = $this->fresh()->postJson('https://share.example.test/api/users/me/passkeys/options', ['password' => 'password'], $this->bearer($user))
            ->json('data.options');
        $secure = new SoftwareAuthenticator('share.example.test', 'https://share.example.test');
        $this->fresh()->postJson('https://share.example.test/api/users/me/passkeys', ['credential' => $secure->register($options)], $this->bearer($user))
            ->assertOk();
    }

    // --- As a second factor ---

    public function test_the_password_alone_opens_no_session_once_a_passkey_is_added(): void
    {
        $user = $this->user();
        $this->addPasskey($user);

        $this->login($user)
            ->assertOk()
            ->assertJsonPath('status', 'mfa_required')
            ->assertJsonPath('data.methods', ['passkey', 'recovery'])
            ->assertJsonMissingPath('data.access_token')
            ->assertCookieMissing('refresh_token');
    }

    public function test_a_valid_signature_opens_the_session(): void
    {
        $user = $this->user();
        $authenticator = $this->addPasskey($user);
        [$token, $options] = $this->secondFactorChallenge($user);

        $this->assertSame([rtrim(strtr(base64_encode($authenticator->credentialId), '+/', '-_'), '=')], array_column($options['allowCredentials'], 'id'));

        $this->sendAssertion($token, $authenticator->assert($options))
            ->assertOk()
            ->assertJsonStructure(['data' => ['access_token']])
            ->assertPlainCookie('refresh_token');

        $this->assertNotNull($user->passkeys()->first()->last_used_at);
    }

    public function test_a_signature_from_another_key_is_refused(): void
    {
        $user = $this->user();
        $authenticator = $this->addPasskey($user);
        [$token, $options] = $this->secondFactorChallenge($user);

        $this->sendAssertion($token, $authenticator->impostor()->assert($options))
            ->assertUnauthorized()
            ->assertJson(['code' => 'mfa_invalid_passkey'])
            ->assertJsonMissingPath('data.access_token')
            ->assertCookieMissing('refresh_token');
    }

    public function test_the_passkey_of_someone_else_is_refused(): void
    {
        $victim = $this->user();
        $attacker = $this->user();
        $this->addPasskey($victim);
        $attackerKey = $this->addPasskey($attacker);
        [$token, $options] = $this->secondFactorChallenge($victim);

        $this->sendAssertion($token, $attackerKey->assert($options))->assertUnauthorized();
    }

    public function test_a_signature_for_another_site_is_refused(): void
    {
        $user = $this->user();
        $authenticator = $this->addPasskey($user);
        [$token, $options] = $this->secondFactorChallenge($user);

        // A phishing page asks the browser to sign: the origin it reports is its own
        $authenticator->origin = 'https://evil.example';

        $this->sendAssertion($token, $authenticator->assert($options))->assertUnauthorized();
    }

    public function test_an_old_signature_cannot_be_replayed(): void
    {
        $user = $this->user();
        $authenticator = $this->addPasskey($user);
        [$token, $options] = $this->secondFactorChallenge($user);
        $assertion = $authenticator->assert($options);
        $this->sendAssertion($token, $assertion)->assertOk();

        [$newToken] = $this->secondFactorChallenge($user);

        $this->sendAssertion($newToken, $assertion)->assertUnauthorized();
    }

    public function test_a_cloned_authenticator_is_detected_by_its_counter(): void
    {
        $user = $this->user();
        $authenticator = $this->addPasskey($user);
        $authenticator->counter = 10;
        [$token, $options] = $this->secondFactorChallenge($user);
        $this->sendAssertion($token, $authenticator->assert($options))->assertOk();

        $authenticator->counter = 2;
        [$token, $options] = $this->secondFactorChallenge($user);

        $this->sendAssertion($token, $authenticator->assert($options))->assertUnauthorized();
    }

    public function test_failed_signatures_count_towards_the_lock_of_the_account(): void
    {
        $user = $this->user();
        $authenticator = $this->addPasskey($user);

        for ($i = 0; $i < 5; $i++) {
            [$token, $options] = $this->secondFactorChallenge($user);
            $this->sendAssertion($token, $authenticator->impostor()->assert($options))->assertUnauthorized();
        }

        [$token, $options] = $this->secondFactorChallenge($user);

        $this->sendAssertion($token, $authenticator->assert($options))
            ->assertStatus(429)
            ->assertJson(['code' => 'mfa_too_many_attempts'])
            ->assertJsonMissingPath('data.access_token');
    }

    public function test_options_need_a_valid_challenge(): void
    {
        $this->postJson('/api/auth/mfa/passkey/options', ['mfa_token' => 'not-a-challenge'])
            ->assertUnauthorized()
            ->assertJson(['code' => 'mfa_expired']);
    }

    // --- Managing them ---

    public function test_removing_a_passkey_needs_the_password(): void
    {
        $user = $this->user();
        $this->addPasskey($user);
        $id = $user->passkeys()->first()->id;

        $this->fresh()->deleteJson("/api/users/me/passkeys/{$id}", ['password' => 'wrong'], $this->bearer($user))
            ->assertStatus(422)
            ->assertJson(['code' => 'invalid_password']);

        $this->assertSame(1, $user->passkeys()->count());
    }

    public function test_removing_the_last_factor_turns_it_all_off(): void
    {
        $user = $this->user();
        $this->addPasskey($user);
        $id = $user->passkeys()->first()->id;

        $this->fresh()->deleteJson("/api/users/me/passkeys/{$id}", ['password' => 'password'], $this->bearer($user))
            ->assertOk()
            ->assertJsonPath('data.enabled', false);

        $this->assertNull($user->fresh()->two_factor_recovery_codes);
        $this->login($user)->assertOk()->assertJsonPath('status', 'success');
    }

    public function test_nobody_can_remove_the_passkey_of_someone_else(): void
    {
        $owner = $this->user();
        $this->addPasskey($owner);
        $id = $owner->passkeys()->first()->id;

        $this->fresh()->deleteJson("/api/users/me/passkeys/{$id}", ['password' => 'password'], $this->bearer($this->user()));

        $this->assertSame(1, $owner->passkeys()->count());
    }

    public function test_removing_the_app_keeps_the_recovery_codes_while_a_passkey_remains(): void
    {
        $user = $this->user();
        $this->addPasskey($user);
        $secret = $this->fresh()->postJson('/api/users/me/2fa/totp/setup', ['password' => 'password'], $this->bearer($user))->json('data.secret');
        $this->fresh()->postJson('/api/users/me/2fa/totp/confirm', ['code' => Totp::codeAt($secret, Totp::timestep())], $this->bearer($user))
            ->assertOk()
            ->assertJsonPath('data.recovery_codes', null);

        $this->fresh()->deleteJson('/api/users/me/2fa/totp', ['password' => 'password'], $this->bearer($user))->assertOk();

        $this->assertNotNull($user->fresh()->two_factor_recovery_codes);
        $this->login($user)->assertJsonPath('status', 'mfa_required')->assertJsonPath('data.methods', ['passkey', 'recovery']);
    }

    public function test_the_status_lists_passkeys_without_their_credentials(): void
    {
        $user = $this->user();
        $this->addPasskey($user);

        $response = $this->fresh()->getJson('/api/users/me/2fa', $this->bearer($user))
            ->assertOk()
            ->assertJsonPath('data.enabled', true)
            ->assertJsonPath('data.passkeys.0.name', 'Office laptop');

        $this->assertStringNotContainsString('credential_source', $response->getContent());
        $this->assertStringNotContainsString('credential_id', $response->getContent());
    }

    public function test_an_admin_sees_who_has_a_passkey_and_can_remove_it(): void
    {
        $admin = $this->user(['admin' => true]);
        $user = $this->user();
        $this->addPasskey($user);

        $users = collect($this->fresh()->getJson('/api/users', $this->bearer($admin))->assertOk()->json('data.users'));
        $this->assertTrue($users->firstWhere('id', $user->id)['passkeys_exists']);
        $this->assertStringNotContainsString('credential_source', json_encode($users));

        $this->fresh()->deleteJson("/api/users/{$user->id}/2fa", [], $this->bearer($admin))->assertOk();

        $this->assertSame(0, $user->passkeys()->count());
        $this->login($user)->assertOk()->assertJsonPath('status', 'success');
    }

    public function test_the_command_removes_passkeys_too(): void
    {
        $user = $this->user();
        $this->addPasskey($user);

        $this->artisan('reset-2fa', ['email' => $user->email])->assertSuccessful();

        $this->assertSame(0, $user->passkeys()->count());
    }

    // --- Under the policy ---

    public function test_the_last_factor_cannot_be_removed_while_it_is_required(): void
    {
        $user = $this->user();
        $this->addPasskey($user);
        $secret = $this->fresh()->postJson('/api/users/me/2fa/totp/setup', ['password' => 'password'], $this->bearer($user))->json('data.secret');
        $this->fresh()->postJson('/api/users/me/2fa/totp/confirm', ['code' => Totp::codeAt($secret, Totp::timestep())], $this->bearer($user))->assertOk();
        $this->policy('everyone');
        $id = $user->passkeys()->first()->id;

        // With two factors, either can go
        $this->fresh()->deleteJson("/api/users/me/passkeys/{$id}", ['password' => 'password'], $this->bearer($user))->assertOk();

        // The last one cannot
        $this->fresh()->deleteJson('/api/users/me/2fa/totp', ['password' => 'password'], $this->bearer($user))
            ->assertStatus(422)
            ->assertJson(['code' => 'mfa_required_by_policy']);
    }

    public function test_the_last_passkey_cannot_be_removed_while_it_is_required(): void
    {
        $user = $this->user();
        $this->addPasskey($user);
        $this->policy('everyone');
        $id = $user->passkeys()->first()->id;

        $this->fresh()->deleteJson("/api/users/me/passkeys/{$id}", ['password' => 'password'], $this->bearer($user))
            ->assertStatus(422)
            ->assertJson(['code' => 'mfa_required_by_policy']);
    }

    public function test_a_passkey_can_be_the_factor_set_up_at_sign_in(): void
    {
        $this->policy('everyone');
        $user = $this->user();
        $token = $this->login($user)->assertJsonPath('status', 'mfa_enrolment_required')->json('data.enrol_token');
        $authenticator = new SoftwareAuthenticator();

        $options = $this->fresh()->postJson('/api/auth/mfa/enrol/passkey/options', ['enrol_token' => $token])->assertOk()->json('data.options');

        $response = $this->fresh()->postJson('/api/auth/mfa/enrol/passkey', [
            'enrol_token' => $token,
            'name' => 'Phone',
            'credential' => $authenticator->register($options),
        ])->assertOk()->assertJsonStructure(['data' => ['access_token', 'recovery_codes']]);

        $response->assertPlainCookie('refresh_token');
        $this->assertCount(10, $response->json('data.recovery_codes'));

        // The token is spent: the factor just set up cannot be replaced with it
        $this->fresh()->postJson('/api/auth/mfa/enrol/passkey/options', ['enrol_token' => $token])
            ->assertUnauthorized()
            ->assertJson(['code' => 'mfa_enrolment_expired']);
        $this->login($user)->assertJsonPath('status', 'mfa_required');
    }

    public function test_a_failed_passkey_at_sign_in_opens_nothing(): void
    {
        $this->policy('everyone');
        $token = $this->login($this->user())->json('data.enrol_token');
        $options = $this->fresh()->postJson('/api/auth/mfa/enrol/passkey/options', ['enrol_token' => $token])->json('data.options');
        $authenticator = new SoftwareAuthenticator('localhost', 'https://evil.example');

        $this->fresh()->postJson('/api/auth/mfa/enrol/passkey', ['enrol_token' => $token, 'credential' => $authenticator->register($options)])
            ->assertStatus(422)
            ->assertJsonMissingPath('data.access_token')
            ->assertCookieMissing('refresh_token');
    }

    // --- Signing in without a password ---

    public function test_it_is_off_by_default(): void
    {
        $this->assertSame('false', Setting::where('key', 'passkey_login_enabled')->value('value'));

        $this->postJson('/api/auth/passkey/options')->assertNotFound();
        $this->postJson('/api/auth/passkey/login', ['token' => 'x', 'credential' => ['id' => 'x']])->assertNotFound();
    }

    public function test_seeding_on_every_start_keeps_it_on(): void
    {
        $this->enablePasswordless();

        $this->seed(SettingsSeeder::class);

        $this->assertSame('true', Setting::where('key', 'passkey_login_enabled')->value('value'));
    }

    public function test_the_login_screen_is_told_when_it_is_on(): void
    {
        $this->enablePasswordless();

        $this->get('/')->assertOk()->assertSee('passkey_login_enabled', false);
    }

    /** @return array{0: string, 1: array} */
    private function passwordlessOptions(): array
    {
        $data = $this->fresh()->postJson('/api/auth/passkey/options')->assertOk()->json('data');

        return [$data['token'], $data['options']];
    }

    private function passwordlessLogin(string $token, array $credential): TestResponse
    {
        return $this->fresh()->postJson('/api/auth/passkey/login', ['token' => $token, 'credential' => $credential]);
    }

    public function test_a_passkey_alone_opens_the_session(): void
    {
        $user = $this->user();
        $authenticator = $this->addPasskey($user);
        $this->enablePasswordless();
        [$token, $options] = $this->passwordlessOptions();

        $this->assertSame([], $options['allowCredentials'] ?? []);
        $this->assertSame('required', $options['userVerification']);

        $this->passwordlessLogin($token, $authenticator->assert($options, (string) $user->id))
            ->assertOk()
            ->assertJsonStructure(['data' => ['access_token']])
            ->assertPlainCookie('refresh_token');
    }

    // The accounts of the organisation sign in through its identity provider, not with a passkey alone
    public function test_a_passkey_alone_opens_no_session_for_a_member_of_a_required_sso_domain(): void
    {
        $user = $this->user();
        $user->forceFill(['email' => 'camille@entreprise.fr'])->save();
        $authenticator = $this->addPasskey($user);
        $this->enablePasswordless();
        Setting::where('key', 'sso_required_domains')->update(['value' => 'entreprise.fr']);
        app(SettingsService::class)->clearCache();
        [$token, $options] = $this->passwordlessOptions();

        $this->passwordlessLogin($token, $authenticator->assert($options, (string) $user->id))
            ->assertForbidden()
            ->assertJsonPath('code', 'sso_required')
            ->assertCookieMissing('refresh_token');
    }

    /**
     * Without it, a stolen device or security key would be enough: nothing
     * replaces the password that this sign-in skips.
     */
    public function test_user_verification_is_mandatory(): void
    {
        $user = $this->user();
        $authenticator = $this->addPasskey($user);
        $this->enablePasswordless();
        [$token, $options] = $this->passwordlessOptions();

        $this->passwordlessLogin($token, $authenticator->assert($options, (string) $user->id, userVerified: false))
            ->assertUnauthorized()
            ->assertJson(['code' => 'passkey_login_failed'])
            ->assertCookieMissing('refresh_token');
    }

    public function test_an_unknown_key_is_refused(): void
    {
        $this->enablePasswordless();
        [$token, $options] = $this->passwordlessOptions();

        $this->passwordlessLogin($token, (new SoftwareAuthenticator())->assert($options, '1'))->assertUnauthorized();
    }

    public function test_a_key_cannot_sign_in_as_someone_else(): void
    {
        $victim = $this->user();
        $attacker = $this->user();
        $authenticator = $this->addPasskey($attacker);
        $this->enablePasswordless();
        [$token, $options] = $this->passwordlessOptions();

        $this->passwordlessLogin($token, $authenticator->assert($options, (string) $victim->id))->assertUnauthorized();
    }

    public function test_a_signature_that_names_no_account_cannot_sign_in(): void
    {
        $authenticator = $this->addPasskey($this->user());
        $this->enablePasswordless();
        [$token, $options] = $this->passwordlessOptions();

        $this->passwordlessLogin($token, $authenticator->assert($options, null))->assertUnauthorized();
    }

    public function test_a_signature_from_another_key_cannot_sign_in(): void
    {
        $user = $this->user();
        $authenticator = $this->addPasskey($user);
        $this->enablePasswordless();
        [$token, $options] = $this->passwordlessOptions();

        $this->passwordlessLogin($token, $authenticator->impostor()->assert($options, (string) $user->id))->assertUnauthorized();
    }

    public function test_a_disabled_account_cannot_sign_in(): void
    {
        $user = $this->user();
        $authenticator = $this->addPasskey($user);
        $this->enablePasswordless();
        $user->forceFill(['active' => false])->save();
        [$token, $options] = $this->passwordlessOptions();

        $this->passwordlessLogin($token, $authenticator->assert($options, (string) $user->id))
            ->assertUnauthorized()
            ->assertCookieMissing('refresh_token');
    }

    public function test_the_options_work_only_once(): void
    {
        $user = $this->user();
        $authenticator = $this->addPasskey($user);
        $this->enablePasswordless();
        [$token, $options] = $this->passwordlessOptions();
        $this->passwordlessLogin($token, $authenticator->assert($options, (string) $user->id))->assertOk();

        $this->passwordlessLogin($token, $authenticator->assert($options, (string) $user->id))->assertUnauthorized();
    }

    public function test_its_sessions_keep_being_renewed_under_the_policy(): void
    {
        $user = $this->user();
        $authenticator = $this->addPasskey($user);
        $this->enablePasswordless();
        $this->policy('everyone');
        [$token, $options] = $this->passwordlessOptions();
        $cookie = $this->passwordlessLogin($token, $authenticator->assert($options, (string) $user->id))->getCookie('refresh_token', false)->getValue();

        $renewed = $this->fresh()->withCredentials()->withUnencryptedCookie('refresh_token', $cookie)->postJson('/api/auth/refresh')->assertOk();

        $this->assertNotNull($renewed->getCookie('refresh_token', false));
    }

    public function test_asking_for_options_is_limited(): void
    {
        $this->enablePasswordless();

        for ($i = 0; $i < 30; $i++) {
            $this->postJson('/api/auth/passkey/options')->assertOk();
        }

        $this->postJson('/api/auth/passkey/options')->assertStatus(429);
    }

    /**
     * The database cache only removes an expired entry when it is read again:
     * abandoned sign-ins, which anyone can start, would stay forever.
     */
    public function test_the_daily_maintenance_clears_expired_cache_entries(): void
    {
        config(['cache.default' => 'database']);
        DB::table('cache')->insert([
            ['key' => 'expired', 'value' => 'x', 'expiration' => now()->subMinute()->getTimestamp()],
            ['key' => 'alive', 'value' => 'x', 'expiration' => now()->addHour()->getTimestamp()],
        ]);

        (new maintainDb())->handle();

        $this->assertSame(['alive'], DB::table('cache')->whereIn('key', ['expired', 'alive'])->pluck('key')->all());
    }
}
