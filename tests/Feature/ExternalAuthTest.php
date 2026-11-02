<?php

namespace Tests\Feature;

use App\AuthProviders\AuthProviderUser;
use App\AuthProviders\TestingAuthProvider;
use App\Models\AuthProvider;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

require_once __DIR__.'/../Support/TestingAuthProvider.php';

/**
 * Parcours SSO de bout en bout avec un fournisseur factice : ce sont ces
 * réglages (liaison de compte, confiance dans l'e-mail, création de compte)
 * qui doivent survivre à chaque mise à jour.
 */
class ExternalAuthTest extends TestCase
{
    use RefreshDatabase;

    protected $seed = true;

    protected function tearDown(): void
    {
        TestingAuthProvider::$nextUser = null;
        parent::tearDown();
    }

    private function makeProvider(array $attributes = []): AuthProvider
    {
        $provider = new AuthProvider();
        $provider->forceFill(array_merge([
            'name' => 'Test IdP',
            'provider_class' => 'Testing',
            'provider_config' => [],
            'enabled' => true,
            'uuid' => (string) Str::uuid(),
            'allow_registration' => false,
            'trust_email' => false,
        ], $attributes))->save();

        return $provider;
    }

    private function makeUser(array $attributes = []): User
    {
        return User::factory()->create(array_merge([
            'admin' => false,
            'active' => true,
            'must_change_password' => false,
            'is_guest' => false,
        ], $attributes));
    }

    private function identityProviderReturns(string $sub, string $email): void
    {
        TestingAuthProvider::$nextUser = new AuthProviderUser([
            'sub' => $sub,
            'name' => 'Jane Doe',
            'email' => $email,
            'avatar' => null,
            'verified' => true,
        ]);
    }

    private function link(User $user, AuthProvider $provider, string $sub): void
    {
        $user->authProviders()->attach($provider->id, [
            'provider_user_id' => $sub,
            'provider_email' => $user->email,
        ]);
    }

    private function completeLogin(AuthProvider $provider)
    {
        return $this->get("/auth/provider/{$provider->uuid}/callback");
    }

    public function test_the_callback_url_registered_at_identity_providers_does_not_change(): void
    {
        // Changer cette URL casserait la configuration côté Entra ID, Google, Authentik…
        $this->assertStringEndsWith(
            '/auth/provider/0f8b6b1e-uuid/callback',
            route('social.provider.callback', ['provider' => '0f8b6b1e-uuid'])
        );
    }

    public function test_login_redirects_to_the_identity_provider(): void
    {
        $provider = $this->makeProvider();

        $this->get("/auth/provider/{$provider->id}/login")
            ->assertRedirect(TestingAuthProvider::AUTHORIZE_URL);
    }

    public function test_a_disabled_provider_cannot_start_a_login(): void
    {
        $provider = $this->makeProvider(['enabled' => false]);

        $response = $this->get("/auth/provider/{$provider->id}/login");

        $this->assertNotSame(TestingAuthProvider::AUTHORIZE_URL, $response->headers->get('Location'));
    }

    public function test_a_disabled_provider_cannot_complete_a_login(): void
    {
        $provider = $this->makeProvider(['enabled' => false]);
        $user = $this->makeUser();
        $this->link($user, $provider, 'sub-123');
        $this->identityProviderReturns('sub-123', $user->email);

        $this->completeLogin($provider)->assertCookieMissing('refresh_token');
    }

    public function test_a_linked_account_logs_in_and_receives_a_refresh_cookie(): void
    {
        $provider = $this->makeProvider();
        $user = $this->makeUser();
        $this->link($user, $provider, 'sub-123');
        $this->identityProviderReturns('sub-123', $user->email);

        $response = $this->completeLogin($provider);

        $response->assertRedirect();
        $response->assertPlainCookie('refresh_token');
    }

    public function test_an_unknown_identity_is_refused_when_email_is_not_trusted(): void
    {
        $provider = $this->makeProvider(['trust_email' => false]);
        $user = $this->makeUser();
        $this->identityProviderReturns('someone-else', $user->email);

        $this->completeLogin($provider)->assertCookieMissing('refresh_token');
        $this->assertSame(0, $user->authProviders()->count());
    }

    public function test_a_trusted_email_links_the_existing_account(): void
    {
        $provider = $this->makeProvider(['trust_email' => true]);
        $user = $this->makeUser();
        $this->identityProviderReturns('sub-456', $user->email);

        $this->completeLogin($provider)->assertPlainCookie('refresh_token');

        $this->assertDatabaseHas('user_auth_provider', [
            'user_id' => $user->id,
            'auth_provider_id' => $provider->id,
            'provider_user_id' => 'sub-456',
        ]);
    }

    public function test_registration_creates_an_account_when_allowed(): void
    {
        $provider = $this->makeProvider(['allow_registration' => true]);
        $this->identityProviderReturns('sub-789', 'new.colleague@example.test');

        $this->completeLogin($provider)->assertPlainCookie('refresh_token');

        $this->assertDatabaseHas('users', ['email' => 'new.colleague@example.test', 'admin' => false]);
    }

    public function test_registration_is_refused_when_not_allowed(): void
    {
        $provider = $this->makeProvider(['allow_registration' => false]);
        $this->identityProviderReturns('sub-789', 'new.colleague@example.test');

        $this->completeLogin($provider)->assertCookieMissing('refresh_token');

        $this->assertDatabaseMissing('users', ['email' => 'new.colleague@example.test']);
    }

    public function test_an_inactive_account_cannot_log_in_through_sso(): void
    {
        $provider = $this->makeProvider();
        $user = $this->makeUser(['active' => false]);
        $this->link($user, $provider, 'sub-123');
        $this->identityProviderReturns('sub-123', $user->email);

        $this->completeLogin($provider)->assertCookieMissing('refresh_token');
    }

    public function test_an_identity_provider_failure_redirects_instead_of_crashing(): void
    {
        $provider = $this->makeProvider();
        TestingAuthProvider::$nextUser = null;

        $response = $this->completeLogin($provider);

        $response->assertRedirect();
        $response->assertCookieMissing('refresh_token');
    }

    public function test_the_public_provider_list_hides_disabled_providers_and_secrets(): void
    {
        $this->makeProvider(['name' => 'Visible', 'provider_class' => 'Google', 'provider_config' => ['client_secret' => 'top-secret-value']]);
        $this->makeProvider(['name' => 'Hidden', 'provider_class' => 'Google', 'enabled' => false]);

        $response = $this->getJson('/api/available-auth-providers')->assertOk();

        $this->assertStringContainsString('Visible', $response->getContent());
        $this->assertStringNotContainsString('Hidden', $response->getContent());
        $this->assertStringNotContainsString('top-secret-value', $response->getContent());
    }
}
