<?php

namespace Tests\Feature;

use App\Models\AuthProvider;
use App\Models\Setting;
use App\Models\User;
use App\Services\SettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * What a member changes on their account (Mon profil): their address, their password, the link to
 * an identity provider. An admin can turn each off (Administration > SSO); an account that signs
 * in through the identity provider alone (a forced provider, a domain that requires it) changes
 * none of them, whatever the settings: its address and its way in are those of the provider.
 * Admins keep all three; a password an admin asked to change can always be changed.
 */
class AccountChangesTest extends TestCase
{
    use RefreshDatabase;

    protected $seed = true;

    private function member(array $attributes = []): User
    {
        return User::factory()->create(array_merge(['admin' => false, 'active' => true, 'is_guest' => false, 'must_change_password' => false, 'email' => 'camille@ville.example'], $attributes));
    }

    private function setting(string $key, string $value): void
    {
        Setting::where('key', $key)->update(['value' => $value]);
        app(SettingsService::class)->clearCache();
    }

    private function provider(): AuthProvider
    {
        $provider = new AuthProvider();
        $provider->forceFill([
            'name' => 'Keycloak', 'provider_class' => 'Testing', 'provider_config' => [], 'enabled' => true,
            'uuid' => (string) Str::uuid(), 'allow_registration' => false, 'trust_email' => true,
        ])->save();

        return $provider;
    }

    private function forced(): AuthProvider
    {
        $provider = $this->provider();
        $this->setting('sso_forced_provider', (string) $provider->id);

        return $provider;
    }

    private function link(User $user, AuthProvider $provider): void
    {
        $user->authProviders()->attach($provider->id, ['provider_user_id' => 'kc-'.$user->id, 'provider_email' => $user->email]);
    }

    private function changePassword(User $user)
    {
        return $this->actingAs($user, 'api')->putJson('/api/users/me', [
            'password' => 'a-brand-new-password', 'password_confirmation' => 'a-brand-new-password', 'current_password' => 'password',
        ]);
    }

    private function changeEmail(User $user)
    {
        return $this->actingAs($user, 'api')->postJson('/api/users/me/email', ['email' => 'nouvelle@ville.example', 'current_password' => 'password']);
    }

    public function test_the_settings_are_there_and_allow_by_default(): void
    {
        foreach (['members_change_email', 'members_change_password', 'members_unlink_providers'] as $key) {
            $this->assertSame('true', Setting::where('key', $key)->value('value'), $key);
            $this->assertSame('system.auth', Setting::where('key', $key)->value('group'), $key);
        }
        $this->changePassword($this->member())->assertOk();
    }

    public function test_an_admin_turns_off_the_change_of_password(): void
    {
        $this->setting('members_change_password', 'false');

        $this->changePassword($this->member())->assertForbidden()->assertJsonPath('code', 'account_change_disabled');
        $this->changePassword($this->member(['email' => 'admin@ville.example', 'admin' => true]))->assertOk();
    }

    public function test_a_password_an_admin_asked_to_change_can_be_changed(): void
    {
        $this->setting('members_change_password', 'false');

        $this->changePassword($this->member(['must_change_password' => true]))->assertOk();
    }

    public function test_an_admin_turns_off_the_change_of_address(): void
    {
        Queue::fake();
        $this->setting('members_change_email', 'false');

        $this->changeEmail($this->member())->assertForbidden()->assertJsonPath('code', 'account_change_disabled');
        Queue::assertNothingPushed();
        $this->changeEmail($this->member(['email' => 'admin@ville.example', 'admin' => true]))->assertOk();
    }

    public function test_an_admin_turns_off_the_unlinking(): void
    {
        $this->setting('members_unlink_providers', 'false');
        $provider = $this->provider();
        $member = $this->member();
        $this->link($member, $provider);

        $this->actingAs($member, 'api')->deleteJson("/api/users/me/providers/{$provider->id}")
            ->assertForbidden()->assertJsonPath('code', 'account_change_disabled');
        $this->assertTrue($member->authProviders()->where('auth_provider_id', $provider->id)->exists());
    }

    public function test_by_default_a_member_unlinks_a_provider(): void
    {
        $provider = $this->provider();
        $member = $this->member();
        $this->link($member, $provider);

        $this->actingAs($member, 'api')->deleteJson("/api/users/me/providers/{$provider->id}")->assertOk();
    }

    // A forced provider: the address, the password and the link are those of the provider
    public function test_an_account_that_signs_in_through_the_provider_alone_changes_none_of_them(): void
    {
        Queue::fake();
        $provider = $this->forced();
        $member = $this->member(['must_change_password' => true]);
        $this->link($member, $provider);

        $this->changePassword($member)->assertForbidden()->assertJsonPath('code', 'sso_required');
        $this->changeEmail($member)->assertForbidden()->assertJsonPath('code', 'sso_required');
        $this->actingAs($member, 'api')->deleteJson("/api/users/me/providers/{$provider->id}")
            ->assertForbidden()->assertJsonPath('code', 'sso_required');
        Queue::assertNothingPushed();
        $this->assertTrue($member->authProviders()->where('auth_provider_id', $provider->id)->exists());
    }

    public function test_the_profile_tells_what_the_account_may_change(): void
    {
        $this->setting('members_change_email', 'false');

        $this->actingAs($this->member(), 'api')->getJson('/api/users/me')->assertOk()
            ->assertJsonPath('data.user.account.change_email', false)
            ->assertJsonPath('data.user.account.change_password', true)
            ->assertJsonPath('data.user.account.unlink_providers', true);

        $this->forced();
        $this->actingAs($this->member(['email' => 'dominique@ville.example']), 'api')->getJson('/api/users/me')->assertOk()
            ->assertJsonPath('data.user.account.change_email', false)
            ->assertJsonPath('data.user.account.change_password', false)
            ->assertJsonPath('data.user.account.unlink_providers', false);
    }

    public function test_an_admin_saves_the_settings(): void
    {
        $admin = $this->member(['email' => 'admin@ville.example', 'admin' => true]);

        $this->actingAs($admin, 'api')->putJson('/api/settings', [
            'settings' => [['key' => 'members_change_password', 'value' => 'false']],
        ])->assertOk();

        $this->assertSame('false', Setting::where('key', 'members_change_password')->value('value'));
    }
}
