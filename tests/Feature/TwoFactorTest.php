<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\Totp;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Password;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Second factor for password sign-in, adapted from the moutonnoireu/erugo-security
 * fork. Once it is on, the password alone must not open a session by any path.
 */
class TwoFactorTest extends TestCase
{
    use RefreshDatabase;

    protected $seed = true;

    private function user(array $attributes = []): User
    {
        return User::factory()->create(array_merge(['admin' => false, 'active' => true, 'is_guest' => false], $attributes));
    }

    /**
     * A real request starts in a fresh process: neither the guard nor the JWT
     * service may remember the user or the token of the previous request.
     */
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

    private function login(User $user, string $password = 'password'): TestResponse
    {
        return $this->fresh()->postJson('/api/auth/login', ['email' => $user->email, 'password' => $password]);
    }

    /**
     * Turns TOTP on through the API.
     *
     * @return array{0: string, 1: string[]} the secret and the recovery codes
     */
    private function enableTotp(User $user): array
    {
        $secret = $this->fresh()->postJson('/api/users/me/2fa/totp/setup', ['password' => 'password'], $this->bearer($user))
            ->assertOk()
            ->json('data.secret');

        $codes = $this->fresh()->postJson('/api/users/me/2fa/totp/confirm', [
            // An earlier step, so that the current code stays usable for the sign-in that follows
            'code' => Totp::codeAt($secret, Totp::timestep() - 1),
        ], $this->bearer($user))->assertOk()->json('data.recovery_codes');

        return [$secret, $codes];
    }

    private function currentCode(string $secret): string
    {
        return Totp::codeAt($secret, Totp::timestep());
    }

    private function challenge(User $user): string
    {
        return $this->login($user)->assertOk()->json('data.mfa_token');
    }

    private function verify(string $token, string $code, string $method = 'totp'): TestResponse
    {
        return $this->fresh()->postJson('/api/auth/mfa/verify', ['mfa_token' => $token, 'method' => $method, 'code' => $code]);
    }

    // --- Accounts without a second factor ---

    public function test_signing_in_without_a_second_factor_is_unchanged(): void
    {
        $this->login($this->user())
            ->assertOk()
            ->assertJsonPath('status', 'success')
            ->assertJsonStructure(['data' => ['access_token']])
            ->assertPlainCookie('refresh_token');
    }

    public function test_a_wrong_password_never_reaches_the_second_factor(): void
    {
        $user = $this->user();
        $this->enableTotp($user);

        $this->login($user, 'wrong')->assertUnauthorized()->assertJsonMissingPath('data.mfa_token');
    }

    // --- Signing in with a second factor ---

    public function test_the_password_alone_opens_no_session(): void
    {
        $user = $this->user();
        $this->enableTotp($user);

        $this->login($user)
            ->assertOk()
            ->assertJsonPath('status', 'mfa_required')
            ->assertJsonPath('data.methods', ['totp', 'recovery'])
            ->assertJsonMissingPath('data.access_token')
            ->assertCookieMissing('refresh_token');
    }

    public function test_the_challenge_token_gives_no_access_to_the_api(): void
    {
        $user = $this->user();
        $this->enableTotp($user);

        $this->fresh()->getJson('/api/users/me', ['Authorization' => 'Bearer '.$this->challenge($user)])->assertUnauthorized();
    }

    public function test_a_correct_code_opens_the_session(): void
    {
        $user = $this->user();
        [$secret] = $this->enableTotp($user);

        $this->verify($this->challenge($user), $this->currentCode($secret))
            ->assertOk()
            ->assertJsonStructure(['data' => ['access_token']])
            ->assertPlainCookie('refresh_token');
    }

    public function test_a_wrong_code_opens_nothing(): void
    {
        $user = $this->user();
        $this->enableTotp($user);

        $this->verify($this->challenge($user), '000000')
            ->assertUnauthorized()
            ->assertJson(['code' => 'mfa_invalid_code'])
            ->assertJsonMissingPath('data.access_token')
            ->assertCookieMissing('refresh_token');
    }

    public function test_an_unknown_challenge_is_refused(): void
    {
        $this->verify('not-a-challenge', '000000')->assertUnauthorized()->assertJson(['code' => 'mfa_expired']);
    }

    public function test_a_challenge_works_only_once(): void
    {
        $user = $this->user();
        [$secret] = $this->enableTotp($user);
        $token = $this->challenge($user);

        $this->verify($token, $this->currentCode($secret))->assertOk();

        $this->verify($token, Totp::codeAt($secret, Totp::timestep() + 1))
            ->assertUnauthorized()
            ->assertJson(['code' => 'mfa_expired']);
    }

    public function test_a_challenge_expires(): void
    {
        $user = $this->user();
        [$secret] = $this->enableTotp($user);
        $token = $this->challenge($user);

        $this->travel(6)->minutes();

        $this->verify($token, $this->currentCode($secret))->assertUnauthorized()->assertJson(['code' => 'mfa_expired']);
    }

    public function test_a_code_cannot_be_used_twice_but_the_next_one_works(): void
    {
        $user = $this->user();
        [$secret] = $this->enableTotp($user);

        $this->verify($this->challenge($user), $this->currentCode($secret))->assertOk();

        $this->verify($this->challenge($user), $this->currentCode($secret))->assertUnauthorized();
        $this->verify($this->challenge($user), Totp::codeAt($secret, Totp::timestep() + 1))->assertOk();
    }

    /**
     * Signing in again gives a new challenge: a limit per challenge alone
     * would give whoever has the password unlimited guesses.
     */
    public function test_wrong_codes_lock_the_account_across_new_challenges(): void
    {
        $user = $this->user();
        [$secret] = $this->enableTotp($user);

        for ($i = 0; $i < 5; $i++) {
            $this->verify($this->challenge($user), '000000')->assertUnauthorized();
        }

        $this->verify($this->challenge($user), $this->currentCode($secret))
            ->assertStatus(429)
            ->assertJson(['code' => 'mfa_too_many_attempts'])
            ->assertJsonMissingPath('data.access_token');
    }

    public function test_the_lock_lifts_with_time(): void
    {
        $user = $this->user();
        [$secret] = $this->enableTotp($user);

        for ($i = 0; $i < 5; $i++) {
            $this->verify($this->challenge($user), '000000');
        }

        $this->travel(16)->minutes();

        $this->verify($this->challenge($user), $this->currentCode($secret))->assertOk();
    }

    public function test_a_lock_does_not_affect_other_accounts(): void
    {
        $locked = $this->user();
        $other = $this->user();
        $this->enableTotp($locked);
        [$secret] = $this->enableTotp($other);

        for ($i = 0; $i < 5; $i++) {
            $this->verify($this->challenge($locked), '000000');
        }

        $this->verify($this->challenge($other), $this->currentCode($secret))->assertOk();
    }

    public function test_a_success_resets_the_count(): void
    {
        $user = $this->user();
        [$secret] = $this->enableTotp($user);

        for ($i = 0; $i < 4; $i++) {
            $this->verify($this->challenge($user), '000000');
        }
        $this->verify($this->challenge($user), $this->currentCode($secret))->assertOk();

        for ($i = 0; $i < 4; $i++) {
            $this->verify($this->challenge($user), '000000')->assertUnauthorized();
        }
        $this->verify($this->challenge($user), Totp::codeAt($secret, Totp::timestep() + 1))->assertOk();
    }

    public function test_an_account_deactivated_meanwhile_cannot_finish_signing_in(): void
    {
        $user = $this->user();
        [$secret] = $this->enableTotp($user);
        $token = $this->challenge($user);

        $user->forceFill(['active' => false])->save();

        $this->verify($token, $this->currentCode($secret))->assertUnauthorized()->assertJsonMissingPath('data.access_token');
    }

    // --- Recovery codes ---

    public function test_a_recovery_code_opens_the_session_once(): void
    {
        $user = $this->user();
        [, $codes] = $this->enableTotp($user);

        $this->assertCount(10, $codes);

        // Typed by hand: case and spaces must not matter
        $this->verify($this->challenge($user), ' '.strtoupper($codes[0]).' ', 'recovery')->assertOk();
        $this->verify($this->challenge($user), $codes[0], 'recovery')->assertUnauthorized();
        $this->verify($this->challenge($user), $codes[1], 'recovery')->assertOk();
    }

    public function test_a_recovery_code_is_not_a_totp_code_and_conversely(): void
    {
        $user = $this->user();
        [$secret, $codes] = $this->enableTotp($user);

        $this->verify($this->challenge($user), $codes[0], 'totp')->assertUnauthorized();
        $this->verify($this->challenge($user), $this->currentCode($secret), 'recovery')->assertUnauthorized();
    }

    public function test_an_unknown_method_is_refused(): void
    {
        $user = $this->user();
        [$secret] = $this->enableTotp($user);

        $this->verify($this->challenge($user), $this->currentCode($secret), 'magic')->assertStatus(422);
    }

    // --- Other ways of getting a session ---

    private function resetPassword(User $user): TestResponse
    {
        return $this->fresh()->postJson('/api/auth/reset-password', [
            'token' => Password::broker()->createToken($user),
            'email' => $user->email,
            'password' => 'a-brand-new-password',
            'password_confirmation' => 'a-brand-new-password',
        ]);
    }

    /**
     * Whoever controls the mailbox can reset the password: this must not be
     * a way around the second factor.
     */
    public function test_a_password_reset_opens_no_session_when_a_second_factor_is_on(): void
    {
        $user = $this->user();
        [$secret] = $this->enableTotp($user);

        $this->resetPassword($user)->assertOk()->assertCookieMissing('refresh_token');

        // The new password then goes through the second factor as usual
        $this->login($user, 'a-brand-new-password')->assertOk()->assertJsonPath('status', 'mfa_required');
    }

    public function test_a_password_reset_still_opens_a_session_without_a_second_factor(): void
    {
        $this->resetPassword($this->user())->assertOk()->assertPlainCookie('refresh_token');
    }

    // --- Managing it from the profile ---

    public function test_setup_needs_the_password(): void
    {
        $user = $this->user();

        $this->postJson('/api/users/me/2fa/totp/setup', ['password' => 'wrong'], $this->bearer($user))
            ->assertStatus(422)
            ->assertJson(['code' => 'invalid_password'])
            ->assertJsonMissingPath('data.secret');
        $this->fresh()->postJson('/api/users/me/2fa/totp/setup', [], $this->bearer($user))->assertStatus(422);
    }

    /**
     * A stolen session must not be able to replace the authenticator of the
     * account for good: the password is needed for every change.
     */
    public function test_replacing_the_authenticator_needs_the_password_and_keeps_the_codes(): void
    {
        $user = $this->user();
        [, $codes] = $this->enableTotp($user);

        $this->fresh()->postJson('/api/users/me/2fa/totp/setup', ['password' => 'wrong'], $this->bearer($user))->assertStatus(422);

        $secret = $this->fresh()->postJson('/api/users/me/2fa/totp/setup', ['password' => 'password'], $this->bearer($user))
            ->assertOk()
            ->json('data.secret');
        $this->fresh()->postJson('/api/users/me/2fa/totp/confirm', ['code' => Totp::codeAt($secret, Totp::timestep() - 1)], $this->bearer($user))
            ->assertOk()
            ->assertJsonPath('data.recovery_codes', null);

        $this->verify($this->challenge($user), $this->currentCode($secret))->assertOk();
        $this->verify($this->challenge($user), $codes[0], 'recovery')->assertOk();
    }

    public function test_setup_gives_a_secret_and_a_uri_for_the_authenticator_app(): void
    {
        $user = $this->user();

        $data = $this->postJson('/api/users/me/2fa/totp/setup', ['password' => 'password'], $this->bearer($user))->assertOk()->json('data');

        $this->assertStringContainsString('secret='.$data['secret'], $data['otpauth_uri']);
        $this->assertStringContainsString(rawurlencode($user->email), $data['otpauth_uri']);
    }

    public function test_setup_alone_turns_nothing_on(): void
    {
        $user = $this->user();

        $this->postJson('/api/users/me/2fa/totp/setup', ['password' => 'password'], $this->bearer($user))->assertOk();

        $this->assertNull($user->fresh()->totp_enabled_at);
        $this->login($user)->assertOk()->assertJsonPath('status', 'success');
    }

    public function test_a_wrong_code_at_setup_leaves_it_off(): void
    {
        $user = $this->user();
        $this->postJson('/api/users/me/2fa/totp/setup', ['password' => 'password'], $this->bearer($user))->assertOk();

        $this->fresh()->postJson('/api/users/me/2fa/totp/confirm', ['code' => '000000'], $this->bearer($user))
            ->assertStatus(422)
            ->assertJson(['code' => 'mfa_invalid_code']);

        $this->assertNull($user->fresh()->totp_enabled_at);
    }

    public function test_a_setup_that_was_not_started_cannot_be_confirmed(): void
    {
        $this->postJson('/api/users/me/2fa/totp/confirm', ['code' => '000000'], $this->bearer($this->user()))
            ->assertStatus(422)
            ->assertJson(['code' => 'totp_setup_expired']);
    }

    public function test_secrets_are_encrypted_at_rest_and_never_serialized(): void
    {
        $user = $this->user();
        [$secret, $codes] = $this->enableTotp($user);

        $row = (array) DB::table('users')->where('id', $user->id)->first();
        $this->assertStringNotContainsString($secret, json_encode($row));
        $this->assertStringNotContainsString($codes[0], json_encode($row));

        $profile = $this->fresh()->getJson('/api/users/me', $this->bearer($user))->assertOk()->getContent();
        $this->assertStringNotContainsString('totp_secret', $profile);
        $this->assertStringNotContainsString('two_factor_recovery_codes', $profile);
        $this->assertStringNotContainsString('totp_last_timestep', $profile);
    }

    public function test_the_status_tells_what_is_on_without_any_secret(): void
    {
        $user = $this->user();
        $headers = $this->bearer($user);

        $this->getJson('/api/users/me/2fa', $headers)->assertOk()->assertJson(['data' => [
            'enabled' => false, 'totp_enabled' => false, 'recovery_codes_remaining' => 0, 'required' => false,
        ]]);

        [$secret, $codes] = $this->enableTotp($user);

        $response = $this->fresh()->getJson('/api/users/me/2fa', $headers)->assertOk()->assertJson(['data' => [
            'enabled' => true, 'totp_enabled' => true, 'recovery_codes_remaining' => 10,
        ]]);
        $this->assertStringNotContainsString($secret, $response->getContent());
        $this->assertStringNotContainsString($codes[0], $response->getContent());
    }

    public function test_turning_it_off_needs_the_password(): void
    {
        $user = $this->user();
        $this->enableTotp($user);

        $this->fresh()->deleteJson('/api/users/me/2fa/totp', ['password' => 'wrong'], $this->bearer($user))
            ->assertStatus(422)
            ->assertJson(['code' => 'invalid_password']);

        $this->login($user)->assertJsonPath('status', 'mfa_required');
    }

    public function test_turning_it_off_removes_it_entirely(): void
    {
        $user = $this->user();
        $this->enableTotp($user);

        $this->fresh()->deleteJson('/api/users/me/2fa/totp', ['password' => 'password'], $this->bearer($user))->assertOk();

        $this->login($user)->assertOk()->assertJsonPath('status', 'success');
        $fresh = $user->fresh();
        $this->assertNull($fresh->totp_secret);
        $this->assertNull($fresh->two_factor_recovery_codes);
    }

    public function test_new_recovery_codes_need_the_password_and_replace_the_old_ones(): void
    {
        $user = $this->user();
        [, $old] = $this->enableTotp($user);

        $this->fresh()->postJson('/api/users/me/2fa/recovery-codes', ['password' => 'wrong'], $this->bearer($user))->assertStatus(422);

        $new = $this->fresh()->postJson('/api/users/me/2fa/recovery-codes', ['password' => 'password'], $this->bearer($user))
            ->assertOk()
            ->json('data.recovery_codes');

        $this->assertCount(10, $new);
        $this->verify($this->challenge($user), $old[0], 'recovery')->assertUnauthorized();
        $this->verify($this->challenge($user), $new[0], 'recovery')->assertOk();
    }

    public function test_recovery_codes_cannot_be_created_without_a_second_factor(): void
    {
        $this->postJson('/api/users/me/2fa/recovery-codes', ['password' => 'password'], $this->bearer($this->user()))->assertStatus(422);
    }

    /**
     * Otherwise a stolen session could guess the password through these screens.
     */
    public function test_wrong_passwords_on_these_screens_are_limited(): void
    {
        $user = $this->user();

        for ($i = 0; $i < 5; $i++) {
            $this->fresh()->postJson('/api/users/me/2fa/totp/setup', ['password' => 'wrong'], $this->bearer($user))->assertStatus(422);
        }

        $this->fresh()->postJson('/api/users/me/2fa/totp/setup', ['password' => 'password'], $this->bearer($user))
            ->assertStatus(429)
            ->assertJson(['code' => 'mfa_too_many_attempts']);
    }

    // --- When the device is lost ---

    public function test_an_admin_can_remove_the_second_factor_of_a_user(): void
    {
        $admin = $this->user(['admin' => true]);
        $user = $this->user();
        $this->enableTotp($user);

        $this->fresh()->deleteJson("/api/users/{$user->id}/2fa", [], $this->bearer($admin))->assertOk();

        $this->login($user)->assertOk()->assertJsonPath('status', 'success');
    }

    public function test_a_member_cannot_remove_the_second_factor_of_someone_else(): void
    {
        $user = $this->user();
        $this->enableTotp($user);

        $this->fresh()->deleteJson("/api/users/{$user->id}/2fa", [], $this->bearer($this->user()))->assertForbidden();

        $this->login($user)->assertJsonPath('status', 'mfa_required');
    }

    public function test_the_command_removes_the_second_factor_of_a_user(): void
    {
        $user = $this->user();
        $this->enableTotp($user);

        $this->artisan('reset-2fa', ['email' => $user->email])->assertSuccessful();

        $this->login($user)->assertOk()->assertJsonPath('status', 'success');
    }

    public function test_the_command_says_so_when_the_user_does_not_exist(): void
    {
        $this->artisan('reset-2fa', ['email' => 'nobody@example.test'])->assertFailed();
    }
}
