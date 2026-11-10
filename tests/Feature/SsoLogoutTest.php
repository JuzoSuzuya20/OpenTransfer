<?php

namespace Tests\Feature;

use App\AuthProviders\AuthProviderUser;
use App\AuthProviders\KeycloakAuthProvider;
use App\AuthProviders\TestingAuthProvider;
use App\Models\AuthProvider;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

require_once __DIR__.'/../Support/TestingAuthProvider.php';

/**
 * Signing out of OpenTransfer can close the session at the identity provider too: otherwise the
 * next "sign in with Keycloak" opens OpenTransfer again without asking anything, on a shared computer.
 */
class SsoLogoutTest extends TestCase
{
    use RefreshDatabase;

    protected $seed = true;

    protected function tearDown(): void
    {
        TestingAuthProvider::$nextUser = null;
        TestingAuthProvider::$endsSession = false;
        parent::tearDown();
    }

    private function fresh(): static
    {
        $this->app['auth']->forgetGuards();
        $this->app->forgetInstance('tymon.jwt');

        return $this;
    }

    private function signInThroughTheProvider(): string
    {
        $provider = new AuthProvider();
        $provider->forceFill([
            'name' => 'Test IdP', 'provider_class' => 'Testing', 'provider_config' => [], 'enabled' => true,
            'uuid' => (string) Str::uuid(), 'allow_registration' => false, 'trust_email' => false,
        ])->save();
        $user = User::factory()->create(['admin' => false, 'active' => true]);
        $user->authProviders()->attach($provider->id, ['provider_user_id' => 'sub-1', 'provider_email' => $user->email]);
        $identity = new AuthProviderUser(['sub' => 'sub-1', 'name' => 'Camille', 'email' => $user->email, 'avatar' => null, 'verified' => true]);
        $identity->idToken = 'the-id-token';
        TestingAuthProvider::$nextUser = $identity;

        return urldecode($this->get("/auth/provider/{$provider->uuid}/callback")->getCookie('refresh_token', false)->getValue());
    }

    private function logout(?string $refreshToken): TestResponse
    {
        $request = $this->fresh()->withCredentials();
        if ($refreshToken) {
            $request = $request->withUnencryptedCookie('refresh_token', $refreshToken);
        }

        return $request->postJson('/api/auth/logout');
    }

    private function refresh(string $refreshToken): TestResponse
    {
        return $this->fresh()->withCredentials()->withUnencryptedCookie('refresh_token', $refreshToken)->postJson('/api/auth/refresh');
    }

    public function test_signing_out_of_an_sso_session_hands_the_logout_address_of_the_provider(): void
    {
        TestingAuthProvider::$endsSession = true;
        $refreshToken = $this->signInThroughTheProvider();

        $url = $this->logout($refreshToken)->assertOk()->json('data.logout_url');

        $this->assertStringStartsWith(TestingAuthProvider::LOGOUT_URL, $url);
        $this->assertStringContainsString('id_token_hint=the-id-token', $url);
    }

    public function test_the_provider_session_survives_the_renewals_of_the_local_one(): void
    {
        TestingAuthProvider::$endsSession = true;
        $renewed = $this->refresh($this->signInThroughTheProvider())->assertOk()->getCookie('refresh_token', false)->getValue();

        $this->assertNotNull($this->logout($renewed)->json('data.logout_url'));
    }

    public function test_the_logout_address_is_given_once(): void
    {
        TestingAuthProvider::$endsSession = true;
        $refreshToken = $this->signInThroughTheProvider();
        $this->logout($refreshToken)->assertOk();

        $this->assertNull($this->logout($refreshToken)->json('data.logout_url'));
    }

    public function test_a_provider_that_does_not_end_its_session_gives_no_address(): void
    {
        $this->assertNull($this->logout($this->signInThroughTheProvider())->json('data.logout_url'));
    }

    public function test_a_password_session_gives_no_address(): void
    {
        $user = User::factory()->create(['admin' => false, 'active' => true]);
        $refreshToken = $this->postJson('/api/auth/login', ['email' => $user->email, 'password' => 'password'])->getCookie('refresh_token', false)->getValue();

        $this->assertNull($this->logout($refreshToken)->json('data.logout_url'));
        $this->assertNull($this->logout(null)->json('data.logout_url'));
    }

    private function keycloak(bool $logout = true): KeycloakAuthProvider
    {
        $model = new AuthProvider();
        $model->forceFill([
            'name' => 'Keycloak', 'provider_class' => 'Keycloak', 'enabled' => true, 'uuid' => (string) Str::uuid(),
            'provider_config' => ['server_url' => 'https://sso.entreprise.fr', 'realm' => 'entreprise', 'client_id' => 'opentransfer', 'client_secret' => 'x', 'logout_at_provider' => $logout],
        ]);

        return new KeycloakAuthProvider($model);
    }

    public function test_keycloak_builds_its_logout_address_from_its_published_configuration(): void
    {
        Http::fake(['*/.well-known/openid-configuration' => Http::response([
            'issuer' => 'https://sso.entreprise.fr/realms/entreprise',
            'end_session_endpoint' => 'https://sso.entreprise.fr/realms/entreprise/protocol/openid-connect/logout',
        ])]);

        $url = $this->keycloak()->logoutUrl('the-id-token', 'https://transfert.entreprise.fr/');

        $this->assertStringStartsWith('https://sso.entreprise.fr/realms/entreprise/protocol/openid-connect/logout?', $url);
        parse_str(parse_url($url, PHP_URL_QUERY), $query);
        $this->assertSame(['client_id' => 'opentransfer', 'post_logout_redirect_uri' => 'https://transfert.entreprise.fr/', 'id_token_hint' => 'the-id-token'], $query);
    }

    public function test_keycloak_ends_nothing_when_the_admin_turned_it_off(): void
    {
        Http::fake();

        $this->assertNull($this->keycloak(false)->logoutUrl('the-id-token', 'https://transfert.entreprise.fr/'));
        Http::assertNothingSent();
    }

    public function test_an_unreachable_provider_does_not_stop_signing_out(): void
    {
        Http::fake(['*' => Http::response('', 500)]);

        $this->assertNull($this->keycloak()->logoutUrl('the-id-token', 'https://transfert.entreprise.fr/'));
    }
}
