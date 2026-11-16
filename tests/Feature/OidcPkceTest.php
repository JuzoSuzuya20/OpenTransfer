<?php

namespace Tests\Feature;

use App\AuthProviders\KeycloakAuthProvider;
use App\AuthProviders\OIDCAuthProvider;
use App\Models\AuthProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

// The authorisation code of an OpenID Connect sign-in is bound to the browser that asked for it
// (PKCE, S256): a code caught on its way back cannot be exchanged by anyone else, and a Keycloak
// client that requires PKCE (a realm policy, OAuth 2.1) accepts the sign-in.
class OidcPkceTest extends TestCase
{
    use RefreshDatabase;

    private function provider(string $class, array $config): AuthProvider
    {
        $provider = new AuthProvider();
        $provider->forceFill([
            'name' => $class,
            'provider_class' => $class,
            'provider_config' => $config,
            'enabled' => true,
            'uuid' => (string) Str::uuid(),
        ])->save();

        return $provider;
    }

    public function test_an_openid_connect_sign_in_uses_pkce(): void
    {
        $provider = $this->provider('OIDC', ['base_url' => 'https://sso.example.test', 'client_id' => 'opentransfer', 'client_secret' => 'secret']);

        $this->assertSame('S256', (new OIDCAuthProvider($provider))->client()->getCodeChallengeMethod());
    }

    public function test_a_keycloak_sign_in_uses_pkce(): void
    {
        $provider = $this->provider('Keycloak', ['server_url' => 'https://sso.example.test', 'realm' => 'entreprise', 'client_id' => 'opentransfer', 'client_secret' => 'secret']);

        $this->assertSame('S256', (new KeycloakAuthProvider($provider))->client()->getCodeChallengeMethod());
    }
}
