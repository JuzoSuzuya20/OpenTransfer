<?php

namespace Tests\Feature;

use App\Models\AuthProvider;
use App\Models\Setting;
use App\Models\User;
use App\Services\SettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

// Like the "forced" OpenID Connect of ITSM-NG: an admin can make the sign-in page lead straight
// to one identity provider. The page learns where to go only while that provider is enabled, and
// /?noAUTO=1 keeps the form of local accounts (admins) within reach.
class ForcedSsoTest extends TestCase
{
    use RefreshDatabase;

    protected $seed = true;

    private function provider(bool $enabled = true): AuthProvider
    {
        $provider = new AuthProvider();
        $provider->forceFill([
            'name' => 'Keycloak',
            'provider_class' => 'Keycloak',
            'provider_config' => ['server_url' => 'https://sso.example.test', 'realm' => 'entreprise', 'client_id' => 'opentransfer', 'client_secret' => 'secret'],
            'enabled' => $enabled,
            'uuid' => (string) Str::uuid(),
        ])->save();

        return $provider;
    }

    private function force(?AuthProvider $provider): void
    {
        Setting::where('key', 'sso_forced_provider')->update(['value' => $provider ? (string) $provider->id : '']);
        app(SettingsService::class)->clearCache();
    }

    private function admin(): User
    {
        return User::factory()->create(['admin' => true, 'active' => true, 'must_change_password' => false, 'is_guest' => false]);
    }

    private function save(string $value)
    {
        return $this->actingAs($this->admin(), 'api')->putJson('/api/settings', [
            'settings' => [['key' => 'sso_forced_provider', 'value' => $value]],
        ]);
    }

    public function test_the_sign_in_page_leads_to_the_forced_provider(): void
    {
        $provider = $this->provider();
        $this->force($provider);

        $this->assertSame("/auth/provider/{$provider->id}/login", getSettings()['sso_forced_login']);
    }

    public function test_nothing_is_forced_by_default(): void
    {
        $this->provider();

        $this->assertNull(getSettings()['sso_forced_login']);
    }

    public function test_a_disabled_provider_forces_nothing(): void
    {
        $this->force($this->provider(enabled: false));

        $this->assertNull(getSettings()['sso_forced_login']);
    }

    public function test_an_admin_forces_an_existing_provider(): void
    {
        $provider = $this->provider();

        $this->save((string) $provider->id)->assertOk();

        $this->assertSame((string) $provider->id, Setting::where('key', 'sso_forced_provider')->value('value'));
    }

    public function test_a_provider_that_does_not_exist_is_refused(): void
    {
        $this->save('999')->assertUnprocessable()->assertJsonPath('code', 'sso_forced_provider_invalid');

        $this->assertSame('', (string) Setting::where('key', 'sso_forced_provider')->value('value'));
    }

    public function test_the_form_comes_back_when_nothing_is_forced(): void
    {
        $this->force($this->provider());

        $this->save('')->assertOk();

        app(SettingsService::class)->clearCache();
        $this->assertNull(getSettings()['sso_forced_login']);
    }
}
