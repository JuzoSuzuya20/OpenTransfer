<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Connexion par mot de passe, jetons et contrôle d'accès : le socle que
 * les mises à jour automatiques ne doivent jamais casser.
 */
class AuthenticationTest extends TestCase
{
    use RefreshDatabase;

    protected $seed = true;

    private function makeUser(array $attributes = []): User
    {
        return User::factory()->create(array_merge([
            'admin' => false,
            'active' => true,
            'must_change_password' => false,
            'is_guest' => false,
        ], $attributes));
    }

    public function test_valid_credentials_return_an_access_token_and_a_refresh_cookie(): void
    {
        $user = $this->makeUser();

        $response = $this->postJson('/api/auth/login', ['email' => $user->email, 'password' => 'password']);

        $response->assertOk()
            ->assertJsonPath('status', 'success')
            ->assertJsonStructure(['data' => ['access_token', 'token_type', 'expires_in']]);
        $response->assertPlainCookie('refresh_token');
    }

    public function test_a_wrong_password_is_rejected(): void
    {
        $user = $this->makeUser();

        $response = $this->postJson('/api/auth/login', ['email' => $user->email, 'password' => 'not-the-password']);

        $response->assertUnauthorized()->assertJsonMissingPath('data.access_token');
    }

    public function test_an_unknown_email_is_rejected(): void
    {
        $response = $this->postJson('/api/auth/login', ['email' => 'nobody@example.test', 'password' => 'password']);

        $response->assertUnauthorized()->assertJsonMissingPath('data.access_token');
    }

    public function test_an_inactive_account_cannot_log_in(): void
    {
        $user = $this->makeUser(['active' => false]);

        $response = $this->postJson('/api/auth/login', ['email' => $user->email, 'password' => 'password']);

        $response->assertForbidden()->assertJsonMissingPath('data.access_token');
    }

    public function test_a_self_registered_account_cannot_log_in_before_verifying_its_email(): void
    {
        // Sans cette garde, s'inscrire avec une adresse du domaine autorisé
        // qu'on ne possède pas suffisait pour obtenir un compte.
        $user = $this->makeUser([
            'active' => false,
            'email_verification_code' => '123456',
            'email_verification_code_expires_at' => now()->addMinutes(15),
        ]);

        $response = $this->postJson('/api/auth/login', ['email' => $user->email, 'password' => 'password']);

        $response->assertForbidden()->assertJsonMissingPath('data.access_token');
    }

    public function test_a_refresh_token_stops_working_once_the_account_is_deactivated(): void
    {
        $user = $this->makeUser();
        $login = $this->postJson('/api/auth/login', ['email' => $user->email, 'password' => 'password']);
        $refreshToken = $login->getCookie('refresh_token', false)->getValue();
        // Chaque requête réelle repart d'un garde vierge.
        $this->app['auth']->forgetGuards();

        $user->update(['active' => false]);

        $this->withCredentials()
            ->withUnencryptedCookie('refresh_token', $refreshToken)
            ->postJson('/api/auth/refresh')
            ->assertForbidden()
            ->assertJsonMissingPath('data.access_token');
    }

    public function test_a_valid_refresh_token_issues_a_new_access_token(): void
    {
        $user = $this->makeUser();
        $login = $this->postJson('/api/auth/login', ['email' => $user->email, 'password' => 'password']);
        $refreshToken = $login->getCookie('refresh_token', false)->getValue();
        // Chaque requête réelle repart d'un garde vierge.
        $this->app['auth']->forgetGuards();

        $this->withCredentials()
            ->withUnencryptedCookie('refresh_token', $refreshToken)
            ->postJson('/api/auth/refresh')
            ->assertOk()
            ->assertJsonStructure(['data' => ['access_token']]);
    }

    public function test_protected_routes_require_authentication(): void
    {
        $this->getJson('/api/users/me')->assertUnauthorized();
        $this->getJson('/api/shares')->assertUnauthorized();
    }

    public function test_a_regular_user_cannot_reach_admin_routes(): void
    {
        $user = $this->makeUser();

        foreach (['/api/users', '/api/auth-providers', '/api/backups', '/api/email-templates', '/api/stats'] as $uri) {
            $this->actingAs($user, 'api')->getJson($uri)->assertForbidden();
        }
        $this->actingAs($user, 'api')->putJson('/api/settings', [])->assertForbidden();
    }

    public function test_an_admin_can_reach_admin_routes(): void
    {
        $admin = $this->makeUser(['admin' => true]);

        $this->actingAs($admin, 'api')->getJson('/api/users')->assertOk();
        $this->actingAs($admin, 'api')->getJson('/api/auth-providers')->assertOk();
    }

    public function test_first_user_setup_is_closed_once_an_account_exists(): void
    {
        $this->makeUser(['admin' => true]);

        $this->postJson('/api/setup', [
            'name' => 'Intruder',
            'email' => 'intruder@example.test',
            'password' => 'password123',
            'password_confirmation' => 'password123',
        ])->assertNotFound();

        $this->assertDatabaseMissing('users', ['email' => 'intruder@example.test']);
    }
}
