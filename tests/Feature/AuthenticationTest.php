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

    /**
     * Without Accept: application/json, Laravel redirected to a "login" route
     * that does not exist here, which ended in a 500 and a stack in the logs.
     */
    public function test_the_api_answers_401_to_an_invalid_session_whatever_the_client_accepts(): void
    {
        $this->get('/api/users/me', ['Authorization' => 'Bearer not-a-real-token'])->assertUnauthorized();
        $this->get('/api/users/me')->assertUnauthorized();
    }

    public function test_a_refused_login_says_why_in_a_form_the_interface_can_translate(): void
    {
        $disabled = $this->makeUser(['active' => false]);
        $unverified = $this->makeUser(['active' => false, 'email_verification_code' => '424242']);
        $locked = $this->makeUser();

        $this->postJson('/api/auth/login', ['email' => $disabled->email, 'password' => 'password'])
            ->assertForbidden()
            ->assertJson(['code' => 'account_disabled']);
        $this->postJson('/api/auth/login', ['email' => $unverified->email, 'password' => 'password'])
            ->assertForbidden()
            ->assertJson(['code' => 'email_not_verified']);

        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/auth/login', ['email' => $locked->email, 'password' => 'wrong'])->assertUnauthorized();
        }
        $this->postJson('/api/auth/login', ['email' => $locked->email, 'password' => 'wrong'])
            ->assertStatus(429)
            ->assertJson(['code' => 'too_many_attempts']);
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

        $user->forceFill(['active' => false])->save();

        $this->withCredentials()
            ->withUnencryptedCookie('refresh_token', $refreshToken)
            ->postJson('/api/auth/refresh')
            ->assertForbidden()
            ->assertJsonMissingPath('data.access_token');
    }

    // Disabling an account (someone who left) takes effect at once, not when its access token
    // runs out an hour later
    public function test_a_disabled_account_loses_the_api_at_once(): void
    {
        $user = $this->makeUser();
        $token = auth('api')->tokenById($user->id);
        $this->withHeader('Authorization', "Bearer {$token}")->getJson('/api/users/me')->assertOk();

        $user->forceFill(['active' => false])->save();
        $this->app['auth']->forgetGuards();

        $this->withHeader('Authorization', "Bearer {$token}")->getJson('/api/users/me')->assertUnauthorized();
        $this->withHeader('Authorization', "Bearer {$token}")->getJson('/api/shares')->assertUnauthorized();
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
