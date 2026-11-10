<?php

namespace Tests\Feature;

use App\AuthProviders\KeycloakAuthProvider;
use App\AuthProviders\OIDCAuthProvider;
use App\Models\AuthProvider;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Setting up Keycloak takes four values an admin finds in the Keycloak console: the address of the
 * server, the realm, the identifier and the secret of the client. Everything else is derived, and a
 * test tells right away whether the server answers and accepts the client.
 */
class KeycloakSsoTest extends TestCase
{
    use RefreshDatabase;

    protected $seed = true;

    private function admin(): User
    {
        return User::factory()->create(['admin' => true, 'active' => true]);
    }

    private function config(array $overrides = []): array
    {
        return array_merge([
            'server_url' => 'https://sso.entreprise.fr',
            'realm' => 'entreprise',
            'client_id' => 'opentransfer',
            'client_secret' => 's3cret',
            'logout_at_provider' => true,
        ], $overrides);
    }

    private function discovery(string $issuer = 'https://sso.entreprise.fr/realms/entreprise'): array
    {
        return [
            'issuer' => $issuer,
            'authorization_endpoint' => "$issuer/protocol/openid-connect/auth",
            'token_endpoint' => "$issuer/protocol/openid-connect/token",
            'userinfo_endpoint' => "$issuer/protocol/openid-connect/userinfo",
            'end_session_endpoint' => "$issuer/protocol/openid-connect/logout",
        ];
    }

    private function checkConnection(array $config, string $class = 'Keycloak')
    {
        return $this->actingAs($this->admin(), 'api')->postJson('/api/auth-providers/test', ['class' => $class, 'provider_config' => $config]);
    }

    public function test_keycloak_is_offered_with_the_four_values_it_needs(): void
    {
        $types = collect($this->actingAs($this->admin(), 'api')->getJson('/api/auth-providers/available-types')->assertOk()->json('data.providers'));
        $keycloak = $types->firstWhere('class', 'Keycloak');

        $this->assertNotNull($keycloak);
        $this->assertSame(['server_url', 'realm', 'client_id', 'client_secret', 'logout_at_provider'], array_keys($keycloak['provider_config']));
    }

    public function test_the_issuer_is_derived_from_the_server_and_the_realm(): void
    {
        $this->assertSame('https://sso.entreprise.fr/realms/entreprise', KeycloakAuthProvider::issuer((object) $this->config()));
        $this->assertSame('https://sso.entreprise.fr/auth/realms/mon%20realm', KeycloakAuthProvider::issuer((object) $this->config(['server_url' => 'https://sso.entreprise.fr/auth/', 'realm' => 'mon realm'])));
    }

    public function test_the_four_values_are_required_and_checked(): void
    {
        $this->assertTrue(KeycloakAuthProvider::getValidator($this->config())->passes());
        $this->assertTrue(KeycloakAuthProvider::getValidator($this->config(['realm' => 'a/b']))->fails());
        $this->assertTrue(KeycloakAuthProvider::getValidator($this->config(['server_url' => 'ftp://sso.entreprise.fr']))->fails());
        $this->assertTrue(KeycloakAuthProvider::getValidator($this->config(['client_secret' => '']))->fails());
    }

    public function test_an_account_without_a_full_name_still_signs_in(): void
    {
        // Keycloak leaves out "name" when the first and last names are empty
        $user = OIDCAuthProvider::userFromClaims((object) ['sub' => 'abc', 'preferred_username' => 'cmartin', 'email' => 'camille@entreprise.fr', 'email_verified' => true]);
        $this->assertSame('cmartin', $user->name);
        $this->assertTrue($user->verified);

        $named = OIDCAuthProvider::userFromClaims((object) ['sub' => 'abc', 'given_name' => 'Camille', 'family_name' => 'Martin', 'email' => 'camille@entreprise.fr']);
        $this->assertSame('Camille Martin', $named->name);
        $this->assertFalse($named->verified);
    }

    public function test_trusting_the_email_of_a_provider_can_be_set_by_the_admin(): void
    {
        $provider = new AuthProvider();
        $provider->forceFill([
            'name' => 'Keycloak', 'provider_class' => 'Keycloak', 'provider_config' => $this->config(), 'enabled' => true,
            'uuid' => (string) Str::uuid(), 'allow_registration' => false, 'trust_email' => false,
        ])->save();
        $admin = $this->admin();

        $listed = $this->actingAs($admin, 'api')->getJson('/api/auth-providers')->assertOk()->json('data.authProviders.0');
        $this->assertFalse($listed['trust_email']);

        $listed['trust_email'] = true;
        $this->actingAs($admin, 'api')->putJson('/api/auth-providers/bulk-update', ['providers' => [$listed]])->assertOk();

        $this->assertTrue((bool) $provider->fresh()->trust_email);
    }

    public function test_the_connection_test_finds_the_server_and_accepts_the_client(): void
    {
        Http::fake([
            'sso.entreprise.fr/realms/entreprise/.well-known/openid-configuration' => Http::response($this->discovery()),
            // Without a service account the client is refused this grant, but it was recognised
            'sso.entreprise.fr/realms/entreprise/protocol/openid-connect/token' => Http::response(['error' => 'unauthorized_client'], 400),
        ]);

        $this->checkConnection($this->config())
            ->assertOk()
            ->assertJsonPath('data.discovery', 'ok')
            ->assertJsonPath('data.client', 'ok')
            ->assertJsonPath('data.logout', true)
            ->assertJsonPath('data.issuer', 'https://sso.entreprise.fr/realms/entreprise');
    }

    public function test_the_connection_test_tells_a_wrong_secret(): void
    {
        Http::fake([
            '*/.well-known/openid-configuration' => Http::response($this->discovery()),
            '*/protocol/openid-connect/token' => Http::response(['error' => 'invalid_client'], 401),
        ]);

        $this->checkConnection($this->config())->assertOk()->assertJsonPath('data.discovery', 'ok')->assertJsonPath('data.client', 'invalid');
    }

    public function test_the_connection_test_tells_a_wrong_realm(): void
    {
        Http::fake(['*' => Http::response(['error' => 'Realm does not exist'], 404)]);

        $this->checkConnection($this->config(['realm' => 'nope']))->assertOk()->assertJsonPath('data.discovery', 'failed');
    }

    public function test_the_connection_test_refuses_an_issuer_that_is_not_the_expected_one(): void
    {
        Http::fake(['*/.well-known/openid-configuration' => Http::response($this->discovery('https://ailleurs.example/realms/x'))]);

        $this->checkConnection($this->config())->assertOk()->assertJsonPath('data.discovery', 'issuer_mismatch');
    }

    public function test_the_connection_test_works_for_any_openid_connect_provider(): void
    {
        Http::fake([
            'idp.example.test/.well-known/openid-configuration' => Http::response($this->discovery('https://idp.example.test')),
            '*' => Http::response(['error' => 'unauthorized_client'], 400),
        ]);

        $this->checkConnection(['base_url' => 'https://idp.example.test', 'client_id' => 'ot', 'client_secret' => 'x'], 'OIDC')
            ->assertOk()
            ->assertJsonPath('data.discovery', 'ok');
    }

    public function test_the_connection_test_is_for_admins_only_and_checks_its_input(): void
    {
        $member = User::factory()->create(['admin' => false, 'active' => true]);
        $this->actingAs($member, 'api')->postJson('/api/auth-providers/test', ['class' => 'Keycloak', 'provider_config' => $this->config()])->assertForbidden();

        $this->checkConnection($this->config(['server_url' => 'file:///etc/passwd']))->assertStatus(422);
    }
}
