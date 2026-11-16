<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Password;
use Symfony\Component\HttpFoundation\Cookie;
use Tests\TestCase;

// A new password ends the sessions opened before it: a session left on another computer, or a
// cookie someone took, stopped working only at the end of its week. The one that changed the
// password goes on, with a session of its own.
class SessionRevocationTest extends TestCase
{
    use RefreshDatabase;

    protected $seed = true;

    private function fresh(): void
    {
        $this->app['auth']->forgetGuards();
        $this->app->forgetInstance('tymon.jwt');
    }

    private function member(array $attributes = []): User
    {
        return User::factory()->create(array_merge(['admin' => false, 'active' => true, 'must_change_password' => false, 'is_guest' => false], $attributes));
    }

    /** @return array{0: Cookie, 1: string} the refresh cookie and the access token of a sign-in */
    private function signIn(User $user, string $password = 'password'): array
    {
        $this->fresh();
        $response = $this->postJson('/api/auth/login', ['email' => $user->email, 'password' => $password])->assertOk();

        return [$response->getCookie('refresh_token', false), $response->json('data.access_token')];
    }

    private function refresh(string $refreshToken)
    {
        $this->fresh();

        return $this->withCredentials()->withUnencryptedCookie('refresh_token', $refreshToken)->postJson('/api/auth/refresh');
    }

    private function changePassword(string $accessToken)
    {
        $this->fresh();

        return $this->putJson('/api/users/me', [
            'current_password' => 'password',
            'password' => 'Nouveau-mot-2026',
            'password_confirmation' => 'Nouveau-mot-2026',
        ], ['Authorization' => "Bearer {$accessToken}"]);
    }

    public function test_a_new_password_ends_the_other_sessions(): void
    {
        $user = $this->member();
        [$elsewhere] = $this->signIn($user);
        $this->travel(2)->seconds();
        [, $here] = $this->signIn($user);
        $this->travel(2)->seconds();

        $this->changePassword($here)->assertOk();

        $this->refresh($elsewhere->getValue())->assertUnauthorized()->assertJsonPath('code', 'session_revoked');
    }

    public function test_the_session_that_changed_the_password_goes_on(): void
    {
        $user = $this->member();
        [, $here] = $this->signIn($user);
        $this->travel(2)->seconds();

        $renewed = $this->changePassword($here)->assertOk()->getCookie('refresh_token', false);

        $this->assertNotNull($renewed);
        $this->refresh($renewed->getValue())->assertOk();
    }

    public function test_an_access_token_from_before_is_refused(): void
    {
        $user = $this->member();
        [, $elsewhere] = $this->signIn($user);
        $this->travel(2)->seconds();
        [, $here] = $this->signIn($user);
        $this->travel(2)->seconds();

        $this->changePassword($here)->assertOk();

        $this->fresh();
        $this->getJson('/api/users/me', ['Authorization' => "Bearer {$elsewhere}"])->assertUnauthorized()->assertJsonPath('code', 'session_revoked');
    }

    public function test_a_password_reset_by_link_ends_every_session(): void
    {
        $user = $this->member();
        [$cookie] = $this->signIn($user);
        $this->travel(2)->seconds();

        $this->fresh();
        $this->postJson('/api/auth/reset-password', [
            'token' => Password::createToken($user),
            'email' => $user->email,
            'password' => 'Nouveau-mot-2026',
            'password_confirmation' => 'Nouveau-mot-2026',
        ])->assertOk();

        $this->refresh($cookie->getValue())->assertUnauthorized()->assertJsonPath('code', 'session_revoked');
    }

    public function test_a_password_an_admin_sets_ends_the_sessions_of_the_account(): void
    {
        $user = $this->member();
        [$cookie] = $this->signIn($user);
        $this->travel(2)->seconds();
        $admin = $this->member(['admin' => true]);

        $this->fresh();
        $this->actingAs($admin, 'api')->putJson("/api/users/{$user->id}", [
            'name' => $user->name,
            'email' => $user->email,
            'password' => 'Nouveau-mot-2026',
            'password_confirmation' => 'Nouveau-mot-2026',
        ])->assertOk();

        $this->refresh($cookie->getValue())->assertUnauthorized()->assertJsonPath('code', 'session_revoked');
    }

    public function test_a_session_opened_after_the_change_works(): void
    {
        $user = $this->member();
        [, $here] = $this->signIn($user);
        $this->travel(2)->seconds();
        $this->changePassword($here)->assertOk();
        $this->travel(2)->seconds();

        [$cookie, $token] = $this->signIn($user, 'Nouveau-mot-2026');

        $this->refresh($cookie->getValue())->assertOk();
        $this->fresh();
        $this->getJson('/api/users/me', ['Authorization' => "Bearer {$token}"])->assertOk();
    }
}
