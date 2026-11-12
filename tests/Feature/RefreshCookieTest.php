<?php

namespace Tests\Feature;

use App\Models\Setting;
use App\Models\User;
use App\Services\SettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Symfony\Component\HttpFoundation\Cookie;
use Tests\TestCase;

// The refresh cookie keeps a session open for days: signing out ends that session on the server,
// not only in the browser, and an instance served over HTTPS never lets the cookie travel in clear.
class RefreshCookieTest extends TestCase
{
    use RefreshDatabase;

    protected $seed = true;

    private function member(): User
    {
        return User::factory()->create(['admin' => false, 'active' => true, 'must_change_password' => false, 'is_guest' => false]);
    }

    // The address of the instance as its admin set it (the image runs with APP_URL=/)
    private function servedAt(string $url): void
    {
        Setting::updateOrCreate(['key' => 'application_url'], ['value' => $url]);
        app(SettingsService::class)->clearCache();
    }

    /** @return array{0: string, 1: string, 2: Cookie} access token, refresh token, the cookie as sent */
    private function signIn(User $user): array
    {
        $login = $this->postJson('/api/auth/login', ['email' => $user->email, 'password' => 'password'])->assertOk();
        $cookie = $login->getCookie('refresh_token', false);
        // Each real request starts from fresh guards
        $this->app['auth']->forgetGuards();

        return [$login->json('data.access_token'), $cookie->getValue(), $cookie];
    }

    private function refreshWith(string $refreshToken)
    {
        $this->app['auth']->forgetGuards();

        return $this->withCredentials()
            ->withUnencryptedCookie('refresh_token', $refreshToken)
            ->postJson('/api/auth/refresh');
    }

    public function test_signing_out_ends_the_session_the_cookie_held(): void
    {
        [$accessToken, $refreshToken] = $this->signIn($this->member());

        $this->withCredentials()
            ->withUnencryptedCookie('refresh_token', $refreshToken)
            ->withHeader('Authorization', "Bearer {$accessToken}")
            ->postJson('/api/auth/logout')
            ->assertOk();

        // A copy of the cookie taken before (another device, a stolen backup) opens nothing
        $this->refreshWith($refreshToken)->assertUnauthorized();
    }

    public function test_signing_out_also_ends_the_access_token_of_the_page(): void
    {
        [$accessToken, $refreshToken] = $this->signIn($this->member());

        $this->withCredentials()
            ->withUnencryptedCookie('refresh_token', $refreshToken)
            ->withHeader('Authorization', "Bearer {$accessToken}")
            ->postJson('/api/auth/logout')
            ->assertOk();
        $this->app['auth']->forgetGuards();

        $this->withHeader('Authorization', "Bearer {$accessToken}")->getJson('/api/users/me')->assertUnauthorized();
    }

    public function test_signing_out_with_an_expired_access_token_still_ends_the_session(): void
    {
        [, $refreshToken] = $this->signIn($this->member());

        $this->withCredentials()
            ->withUnencryptedCookie('refresh_token', $refreshToken)
            ->postJson('/api/auth/logout')
            ->assertOk();

        $this->refreshWith($refreshToken)->assertUnauthorized();
    }

    public function test_an_instance_served_over_https_keeps_the_cookie_off_plain_connections(): void
    {
        $this->servedAt('https://partage.example.test');

        [, , $cookie] = $this->signIn($this->member());

        $this->assertTrue($cookie->isSecure());
        $this->assertTrue($cookie->isHttpOnly());
    }

    public function test_an_instance_served_over_plain_http_keeps_a_cookie_its_browsers_accept(): void
    {
        $this->servedAt('http://192.168.1.20:9998');

        [, , $cookie] = $this->signIn($this->member());

        // A secure cookie would never come back over http: nobody could stay signed in
        $this->assertFalse($cookie->isSecure());
        $this->assertTrue($cookie->isHttpOnly());
    }

    public function test_the_cookie_that_signing_out_clears_matches_the_one_signing_in_set(): void
    {
        $this->servedAt('https://partage.example.test');
        [$accessToken, $refreshToken] = $this->signIn($this->member());

        $cleared = $this->withCredentials()
            ->withUnencryptedCookie('refresh_token', $refreshToken)
            ->withHeader('Authorization', "Bearer {$accessToken}")
            ->postJson('/api/auth/logout')
            ->getCookie('refresh_token', false);

        $this->assertSame('', $cleared->getValue());
        $this->assertTrue($cleared->isSecure());
        $this->assertTrue($cleared->isCleared());
    }
}
