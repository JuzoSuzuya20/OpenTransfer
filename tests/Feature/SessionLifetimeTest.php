<?php

namespace Tests\Feature;

use App\Models\AuthProvider;
use App\Models\User;
use App\AuthProviders\AuthProviderUser;
use App\AuthProviders\TestingAuthProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Cookie;
use Tests\TestCase;

require_once __DIR__.'/../Support/TestingAuthProvider.php';

// A session lasts seven days from the sign-in that opened it. Renewing the access token along
// the way never pushes that end further: a cookie copied by someone else stops working within
// the week. (The code meant 24 hours but counted minutes: sessions lasted 60 days, renewed.)
class SessionLifetimeTest extends TestCase
{
    use RefreshDatabase;

    protected $seed = true;

    private const WEEK = 7 * 24 * 3600;

    protected function tearDown(): void
    {
        TestingAuthProvider::$nextUser = null;
        parent::tearDown();
    }

    private function member(): User
    {
        return User::factory()->create(['admin' => false, 'active' => true, 'must_change_password' => false, 'is_guest' => false]);
    }

    private function signIn(User $user): Cookie
    {
        $cookie = $this->postJson('/api/auth/login', ['email' => $user->email, 'password' => 'password'])
            ->assertOk()
            ->getCookie('refresh_token', false);
        $this->app['auth']->forgetGuards();

        return $cookie;
    }

    private function refresh(string $refreshToken)
    {
        $this->app['auth']->forgetGuards();

        return $this->withCredentials()->withUnencryptedCookie('refresh_token', $refreshToken)->postJson('/api/auth/refresh');
    }

    public function test_a_session_lasts_seven_days_from_sign_in(): void
    {
        $cookie = $this->signIn($this->member());

        $this->assertEqualsWithDelta(now()->getTimestamp() + self::WEEK, $cookie->getExpiresTime(), 120);
    }

    public function test_renewing_does_not_push_the_end_further(): void
    {
        $user = $this->member();
        $signedInAt = now()->getTimestamp();
        $cookie = $this->signIn($user);

        $this->travel(6)->days();
        $renewed = $this->refresh($cookie->getValue())->assertOk()->getCookie('refresh_token', false);

        // The same end as the first cookie, a day from now, not a week
        $this->assertEqualsWithDelta($signedInAt + self::WEEK, $renewed->getExpiresTime(), 120);
    }

    public function test_after_seven_days_the_session_is_over(): void
    {
        $user = $this->member();
        $cookie = $this->signIn($user);

        $this->travel(3)->days();
        $renewed = $this->refresh($cookie->getValue())->assertOk()->getCookie('refresh_token', false);

        $this->travel(4)->days();
        $this->travel(2)->minutes();
        $this->refresh($renewed->getValue())->assertUnauthorized();
    }

    public function test_a_session_opened_before_the_rule_ends_a_week_after_its_next_renewal(): void
    {
        $user = $this->member();
        // As issued until now: no sign-in time, sixty days of validity
        $old = auth('api')->setTTL(60 * 60 * 24)->tokenById($user->id);

        $renewed = $this->refresh($old)->assertOk()->getCookie('refresh_token', false);

        $this->assertEqualsWithDelta(now()->getTimestamp() + self::WEEK, $renewed->getExpiresTime(), 120);
    }

    public function test_an_sso_session_lasts_seven_days_too(): void
    {
        $provider = new AuthProvider();
        $provider->forceFill([
            'name' => 'Test IdP', 'provider_class' => 'Testing', 'provider_config' => [], 'enabled' => true,
            'uuid' => (string) Str::uuid(), 'allow_registration' => false, 'trust_email' => false,
        ])->save();
        $user = $this->member();
        $user->authProviders()->attach($provider->id, ['provider_user_id' => 'sub-1', 'provider_email' => $user->email]);
        TestingAuthProvider::$nextUser = new AuthProviderUser(['sub' => 'sub-1', 'name' => 'Jane', 'email' => $user->email, 'avatar' => null, 'verified' => true]);

        $cookie = $this->get("/auth/provider/{$provider->uuid}/callback")->getCookie('refresh_token', false);

        $this->assertEqualsWithDelta(now()->getTimestamp() + self::WEEK, $cookie->getExpiresTime(), 120);

        $this->travel(8)->days();
        $this->refresh(urldecode($cookie->getValue()))->assertUnauthorized();
    }
}
