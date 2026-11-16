<?php

namespace Tests\Feature;

use App\Jobs\sendEmail;
use App\Models\AuthProvider;
use App\Models\Setting;
use App\Models\User;
use App\Services\OidcConnectionTest;
use App\Services\SettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * What can be hashed is hashed (the code a self-registered account confirms its address with); the
 * secrets the application must send on to another server (the SMTP password, the client secret of
 * an identity provider) are encrypted with the key of the instance, and never sent back to a
 * browser, not even an admin's: a copy of the database or of a backup does not give them away.
 */
class StoredSecretsTest extends TestCase
{
    use RefreshDatabase;

    protected $seed = true;

    private const SMTP_PASSWORD = 'smtp-Pa55word-of-the-relay';
    private const CLIENT_SECRET = 'kc-client-secret-0f3b9a';

    private function admin(): User
    {
        return User::factory()->create(['admin' => true, 'active' => true, 'must_change_password' => false, 'is_guest' => false]);
    }

    private function saveSmtpPassword(User $admin, string $password)
    {
        return $this->actingAs($admin, 'api')->putJson('/api/settings', ['settings' => [['key' => 'smtp_password', 'value' => $password]]]);
    }

    private function keycloak(array $config = []): AuthProvider
    {
        $provider = new AuthProvider();
        $provider->forceFill([
            'name' => 'Keycloak', 'provider_class' => 'Keycloak', 'enabled' => true,
            'uuid' => (string) Str::uuid(), 'allow_registration' => false, 'trust_email' => false,
            'provider_config' => array_merge([
                'server_url' => 'https://sso.entreprise.fr', 'realm' => 'entreprise',
                'client_id' => 'opentransfer', 'client_secret' => self::CLIENT_SECRET, 'logout_at_provider' => true,
            ], $config),
        ])->save();

        return $provider;
    }

    private function rawProviderConfig(AuthProvider $provider): string
    {
        return (string) DB::table('auth_providers')->where('id', $provider->id)->value('provider_config');
    }

    // ----- the SMTP password ---------------------------------------------------------------------

    public function test_the_smtp_password_is_stored_encrypted(): void
    {
        $this->saveSmtpPassword($this->admin(), self::SMTP_PASSWORD)->assertOk();

        $stored = DB::table('settings')->where('key', 'smtp_password')->value('value');
        $this->assertNotSame(self::SMTP_PASSWORD, $stored);
        $this->assertStringNotContainsString(self::SMTP_PASSWORD, $stored);
        $this->assertSame(self::SMTP_PASSWORD, Crypt::decryptString($stored));
    }

    public function test_the_former_smtp_password_is_not_kept(): void
    {
        $admin = $this->admin();
        $this->saveSmtpPassword($admin, 'the-former-password')->assertOk();
        $this->saveSmtpPassword($admin, self::SMTP_PASSWORD)->assertOk();

        $row = DB::table('settings')->where('key', 'smtp_password')->first();
        $this->assertNull($row->previous_value);
    }

    public function test_the_smtp_password_is_never_sent_back(): void
    {
        $admin = $this->admin();
        $saved = $this->saveSmtpPassword($admin, self::SMTP_PASSWORD)->assertOk();
        $this->assertStringNotContainsString(self::SMTP_PASSWORD, $saved->getContent());

        $group = $this->actingAs($admin, 'api')->getJson('/api/settings/group/system.*')->assertOk();
        $this->assertStringNotContainsString(self::SMTP_PASSWORD, $group->getContent());
        $row = collect($group->json('data.settings'))->firstWhere('key', 'smtp_password');
        $this->assertSame('', $row['value']);
        $this->assertTrue($row['is_set']);

        $one = $this->actingAs($admin, 'api')->getJson('/api/settings/smtp_password')->assertOk();
        $this->assertStringNotContainsString(self::SMTP_PASSWORD, $one->getContent());
        $this->assertNotContains($one->json('data.setting.value'), [self::SMTP_PASSWORD, Setting::where('key', 'smtp_password')->value('value')]);
    }

    public function test_an_empty_smtp_password_keeps_the_one_saved(): void
    {
        $admin = $this->admin();
        $this->saveSmtpPassword($admin, self::SMTP_PASSWORD)->assertOk();
        // What the screen sends back: the field it was given, empty
        $this->saveSmtpPassword($admin, '')->assertOk();

        $this->assertSame(self::SMTP_PASSWORD, Crypt::decryptString(DB::table('settings')->where('key', 'smtp_password')->value('value')));
    }

    public function test_the_mail_is_sent_with_the_smtp_password_in_clear(): void
    {
        $this->saveSmtpPassword($this->admin(), self::SMTP_PASSWORD)->assertOk();

        $this->assertSame(self::SMTP_PASSWORD, app(SettingsService::class)->getMailSettings()['password']);
    }

    // ----- the client secret of an identity provider ---------------------------------------------

    public function test_the_client_secret_is_stored_encrypted(): void
    {
        $provider = $this->keycloak();

        $this->assertStringNotContainsString(self::CLIENT_SECRET, $this->rawProviderConfig($provider));
        // The provider itself reads it in clear, to send it to the identity provider
        $this->assertSame(self::CLIENT_SECRET, AuthProvider::find($provider->id)->provider_config->client_secret);
    }

    public function test_the_client_secret_is_never_sent_back(): void
    {
        $this->keycloak();

        $response = $this->actingAs($this->admin(), 'api')->getJson('/api/auth-providers')->assertOk();

        $this->assertStringNotContainsString(self::CLIENT_SECRET, $response->getContent());
        $listed = $response->json('data.authProviders.0');
        $this->assertSame('', $listed['provider_config']['client_secret']);
        $this->assertTrue($listed['secrets_set']['client_secret']);
    }

    public function test_saving_a_provider_as_listed_keeps_its_client_secret(): void
    {
        $provider = $this->keycloak();
        $admin = $this->admin();
        $listed = $this->actingAs($admin, 'api')->getJson('/api/auth-providers')->assertOk()->json('data.authProviders.0');
        $listed['name'] = 'Keycloak Entreprise';

        $this->actingAs($admin, 'api')->putJson('/api/auth-providers/bulk-update', ['providers' => [$listed]])->assertOk();

        $saved = AuthProvider::find($provider->id);
        $this->assertSame('Keycloak Entreprise', $saved->name);
        $this->assertSame(self::CLIENT_SECRET, $saved->provider_config->client_secret);
        $this->assertStringNotContainsString(self::CLIENT_SECRET, $this->rawProviderConfig($provider));
    }

    public function test_a_new_client_secret_replaces_the_former_one(): void
    {
        $provider = $this->keycloak();
        $admin = $this->admin();
        $listed = $this->actingAs($admin, 'api')->getJson('/api/auth-providers')->assertOk()->json('data.authProviders.0');
        $listed['provider_config']['client_secret'] = 'a-brand-new-client-secret';

        $this->actingAs($admin, 'api')->putJson('/api/auth-providers/bulk-update', ['providers' => [$listed]])->assertOk();

        $this->assertSame('a-brand-new-client-secret', AuthProvider::find($provider->id)->provider_config->client_secret);
        $this->assertStringNotContainsString('a-brand-new-client-secret', $this->rawProviderConfig($provider));
    }

    public function test_testing_a_saved_provider_uses_its_stored_secret(): void
    {
        $provider = $this->keycloak();
        $used = null;
        $this->mock(OidcConnectionTest::class)->shouldReceive('run')->andReturnUsing(function ($issuer, $clientId, $secret) use (&$used) {
            $used = $secret;

            return ['discovery' => 'ok', 'client' => 'accepted', 'logout' => true, 'issuer' => $issuer];
        });

        $this->actingAs($this->admin(), 'api')->postJson('/api/auth-providers/test', [
            'id' => $provider->id,
            'class' => 'Keycloak',
            'provider_config' => ['server_url' => 'https://sso.entreprise.fr', 'realm' => 'entreprise', 'client_id' => 'opentransfer', 'client_secret' => ''],
        ])->assertOk();

        $this->assertSame(self::CLIENT_SECRET, $used);
    }

    // ----- the code of a self-registration ------------------------------------------------------

    public function test_the_registration_code_is_stored_hashed(): void
    {
        Queue::fake();
        Setting::where('key', 'self_registration_enabled')->update(['value' => 'true']);
        Setting::where('key', 'self_registration_allow_any_domain')->update(['value' => 'true']);
        app(SettingsService::class)->clearCache();

        $this->postJson('/api/auth/register', [
            'name' => 'New Colleague', 'email' => 'new@example.test',
            'password' => 'password123', 'password_confirmation' => 'password123',
        ])->assertSuccessful();

        $code = null;
        Queue::assertPushed(sendEmail::class, function (sendEmail $job) use (&$code) {
            $code = $job->mailableData['code'] ?? null;

            return $code !== null;
        });
        $stored = User::where('email', 'new@example.test')->firstOrFail()->email_verification_code;
        $this->assertNotSame($code, $stored);
        $this->assertTrue(Hash::check($code, $stored));

        $this->postJson('/api/auth/verify-email', ['email' => 'new@example.test', 'code' => $code])->assertOk();
        $this->assertTrue((bool) User::where('email', 'new@example.test')->first()->active);
    }

    // ----- what an existing instance already holds ----------------------------------------------

    public function test_an_existing_instance_gets_its_secrets_encrypted_and_its_codes_hashed(): void
    {
        DB::table('settings')->where('key', 'smtp_password')->update(['value' => self::SMTP_PASSWORD, 'previous_value' => 'the-former-password']);
        $id = DB::table('auth_providers')->insertGetId([
            'name' => 'Keycloak', 'provider_class' => 'Keycloak', 'enabled' => true, 'uuid' => (string) Str::uuid(),
            'provider_config' => json_encode(['server_url' => 'https://sso.entreprise.fr', 'realm' => 'entreprise', 'client_id' => 'opentransfer', 'client_secret' => self::CLIENT_SECRET]),
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $pending = User::factory()->create(['active' => false]);
        DB::table('users')->where('id', $pending->id)->update(['email_verification_code' => '424242', 'email_verification_code_expires_at' => now()->addMinutes(10)]);

        $migration = require database_path('migrations/2026_11_17_000001_encrypt_stored_secrets.php');
        $migration->up();
        // Run again at the next start: nothing is encrypted or hashed twice
        $migration->up();
        app(SettingsService::class)->clearCache();

        $row = DB::table('settings')->where('key', 'smtp_password')->first();
        $this->assertSame(self::SMTP_PASSWORD, Crypt::decryptString($row->value));
        $this->assertNull($row->previous_value);
        $this->assertSame(self::SMTP_PASSWORD, app(SettingsService::class)->getMailSettings()['password']);

        $this->assertStringNotContainsString(self::CLIENT_SECRET, (string) DB::table('auth_providers')->where('id', $id)->value('provider_config'));
        $this->assertSame(self::CLIENT_SECRET, AuthProvider::find($id)->provider_config->client_secret);

        $code = DB::table('users')->where('id', $pending->id)->value('email_verification_code');
        $this->assertTrue(Hash::check('424242', $code));
        // The code sent before the update still confirms the address
        $this->postJson('/api/auth/verify-email', ['email' => $pending->email, 'code' => '424242'])->assertOk();
    }
}
