<?php

namespace Tests\Feature;

use App\AuthProviders\AuthProviderUser;
use App\AuthProviders\TestingAuthProvider;
use App\Models\AuthProvider;
use App\Models\File;
use App\Models\ReverseShareInvite;
use App\Models\Setting;
use App\Models\Share;
use App\Models\User;
use App\Services\SettingsService;
use Database\Seeders\SettingsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

require_once __DIR__.'/../Support/TestingAuthProvider.php';

/**
 * Optional allowlist of the networks members may sign in from, adapted from
 * the moutonnoireu/erugo-security fork. Share links and reverse-share guests
 * stay reachable from anywhere: only signing in is restricted.
 */
class LoginIpAllowlistTest extends TestCase
{
    use RefreshDatabase;

    protected $seed = true;

    private const ALLOWED_IP = '203.0.113.10';
    private const OTHER_IP = '198.51.100.7';

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
    }

    protected function tearDown(): void
    {
        TestingAuthProvider::$nextUser = null;
        parent::tearDown();
    }

    private function storedValue(string $key): ?string
    {
        return Setting::where('key', $key)->value('value');
    }

    private function enableAllowlist(string $list = self::ALLOWED_IP."\n10.0.0.0/8"): void
    {
        Setting::where('key', 'login_ip_allowlist_enabled')->update(['value' => 'true']);
        Setting::where('key', 'login_ip_allowlist')->update(['value' => $list]);
        app(SettingsService::class)->clearCache();
    }

    private function user(array $attributes = []): User
    {
        return User::factory()->create(array_merge(['admin' => false, 'active' => true, 'is_guest' => false], $attributes));
    }

    /**
     * A real request starts in a fresh process: the guard must not remember
     * the user of the previous request. Browsers send cookies with their JSON
     * requests, which the test client does not do by default.
     */
    private function fromIp(string $ip): static
    {
        $this->app['auth']->forgetGuards();

        return $this->withCredentials()->withServerVariables(['REMOTE_ADDR' => $ip]);
    }

    private function bearer(User $user): array
    {
        return ['Authorization' => 'Bearer '.auth('api')->tokenById($user->id)];
    }

    private function refreshTokenOf(User $user): string
    {
        return $this->fromIp(self::ALLOWED_IP)
            ->postJson('/api/auth/login', ['email' => $user->email, 'password' => 'password'])
            ->assertOk()
            ->getCookie('refresh_token', false)
            ->getValue();
    }

    private function identityProvider(): AuthProvider
    {
        $provider = new AuthProvider();
        $provider->forceFill([
            'name' => 'Test IdP',
            'provider_class' => 'Testing',
            'provider_config' => [],
            'enabled' => true,
            'uuid' => (string) Str::uuid(),
            'allow_registration' => false,
            'trust_email' => false,
        ])->save();

        return $provider;
    }

    private function share(User $owner, array $attributes = []): Share
    {
        $share = Share::create([
            'user_id' => $owner->id, 'name' => 'Report', 'path' => 'tests-'.Str::random(12),
            'long_id' => 'test-'.Str::lower(Str::random(16)), 'size' => 3, 'file_count' => 1,
            'expires_at' => now()->addDay(), 'status' => 'ready',
        ]);
        $share->forceFill($attributes)->save();
        File::create(['name' => 'report.pdf', 'original_name' => 'report.pdf', 'size' => 3, 'type' => 'application/pdf', 'share_id' => $share->id]);

        return $share;
    }

    // --- Off by default, for instances upgrading from Erugo ---

    public function test_nothing_is_restricted_by_default(): void
    {
        $user = $this->user();

        $this->assertSame('false', $this->storedValue('login_ip_allowlist_enabled'));
        $this->fromIp(self::OTHER_IP)
            ->postJson('/api/auth/login', ['email' => $user->email, 'password' => 'password'])
            ->assertOk();
    }

    public function test_seeding_on_every_start_keeps_a_configured_list(): void
    {
        $this->enableAllowlist();

        $this->seed(SettingsSeeder::class);

        $this->assertSame('true', $this->storedValue('login_ip_allowlist_enabled'));
        $this->assertSame(self::ALLOWED_IP."\n10.0.0.0/8", $this->storedValue('login_ip_allowlist'));
    }

    // --- Signing in ---

    public function test_the_right_password_is_refused_from_outside_the_list(): void
    {
        $user = $this->user();
        $this->enableAllowlist();

        $response = $this->fromIp(self::OTHER_IP)
            ->postJson('/api/auth/login', ['email' => $user->email, 'password' => 'password'])
            ->assertForbidden()
            ->assertJson(['code' => 'ip_not_allowed']);

        $response->assertJsonMissingPath('data.access_token');
        $response->assertCookieMissing('refresh_token');
    }

    public function test_login_works_from_a_listed_address_and_from_a_listed_range(): void
    {
        $user = $this->user();
        $this->enableAllowlist();

        foreach ([self::ALLOWED_IP, '10.20.30.40'] as $ip) {
            $this->fromIp($ip)
                ->postJson('/api/auth/login', ['email' => $user->email, 'password' => 'password'])
                ->assertOk();
        }
    }

    #[DataProvider('signInRoutes')]
    public function test_every_way_of_getting_a_session_is_refused_from_outside_the_list(string $uri): void
    {
        $this->enableAllowlist();

        $this->fromIp(self::OTHER_IP)->postJson($uri, [])->assertForbidden()->assertJson(['code' => 'ip_not_allowed']);
    }

    public static function signInRoutes(): array
    {
        return [
            'forgot password' => ['/api/auth/forgot-password'],
            'reset password' => ['/api/auth/reset-password'],
            'register' => ['/api/auth/register'],
            'verify email' => ['/api/auth/verify-email'],
            'resend verification' => ['/api/auth/resend-verification'],
        ];
    }

    // --- SSO ---

    #[DataProvider('ssoRoutes')]
    public function test_sso_routes_are_refused_from_outside_the_list(string $route): void
    {
        $provider = $this->identityProvider();
        $this->enableAllowlist();
        $identifier = $route === 'callback' ? $provider->uuid : $provider->id;

        $this->fromIp(self::OTHER_IP)->get("/auth/provider/{$identifier}/{$route}")->assertForbidden();
    }

    public static function ssoRoutes(): array
    {
        return ['login' => ['login'], 'callback' => ['callback'], 'link' => ['link']];
    }

    public function test_sso_still_works_from_a_listed_address(): void
    {
        $provider = $this->identityProvider();
        $user = $this->user();
        $user->authProviders()->attach($provider->id, ['provider_user_id' => 'sub-1', 'provider_email' => $user->email]);
        TestingAuthProvider::$nextUser = new AuthProviderUser([
            'sub' => 'sub-1', 'name' => 'Jane Doe', 'email' => $user->email, 'avatar' => null, 'verified' => true,
        ]);
        $this->enableAllowlist();

        $this->fromIp(self::ALLOWED_IP)->get("/auth/provider/{$provider->id}/login")
            ->assertRedirect(TestingAuthProvider::AUTHORIZE_URL);
        $this->fromIp(self::ALLOWED_IP)->get("/auth/provider/{$provider->uuid}/callback")
            ->assertPlainCookie('refresh_token');
    }

    // --- Sessions that already exist ---

    public function test_a_member_token_stops_working_outside_the_list(): void
    {
        $user = $this->user();
        $headers = $this->bearer($user);
        $this->enableAllowlist();

        $this->fromIp(self::OTHER_IP)->getJson('/api/users/me', $headers)
            ->assertForbidden()
            ->assertJson(['code' => 'ip_not_allowed']);
        $this->fromIp(self::ALLOWED_IP)->getJson('/api/users/me', $headers)->assertOk();
    }

    public function test_the_refresh_cookie_of_a_member_stops_working_outside_the_list(): void
    {
        $user = $this->user();
        $refreshToken = $this->refreshTokenOf($user);
        $this->enableAllowlist();

        $this->fromIp(self::OTHER_IP)
            ->withUnencryptedCookie('refresh_token', $refreshToken)
            ->postJson('/api/auth/refresh')
            ->assertForbidden()
            ->assertJson(['code' => 'ip_not_allowed']);
        $this->fromIp(self::ALLOWED_IP)
            ->withUnencryptedCookie('refresh_token', $refreshToken)
            ->postJson('/api/auth/refresh')
            ->assertOk();
    }

    public function test_a_private_share_cannot_be_read_with_the_refresh_cookie_from_outside_the_list(): void
    {
        $inviter = $this->user();
        $invite = ReverseShareInvite::create([
            'user_id' => $inviter->id, 'recipient_name' => 'Supplier', 'recipient_email' => 'supplier@example.test',
            'expires_at' => now()->addDay(),
        ]);
        $share = $this->share($inviter, ['public' => false, 'invite_id' => $invite->id]);
        $refreshToken = $this->refreshTokenOf($inviter);
        $this->enableAllowlist();

        $this->fromIp(self::OTHER_IP)
            ->withUnencryptedCookie('refresh_token', $refreshToken)
            ->getJson("/api/shares/{$share->long_id}")
            ->assertNotFound();
        $this->fromIp(self::ALLOWED_IP)
            ->withUnencryptedCookie('refresh_token', $refreshToken)
            ->getJson("/api/shares/{$share->long_id}")
            ->assertOk();
    }

    public function test_an_invalid_token_is_treated_as_any_other_invalid_token(): void
    {
        $this->enableAllowlist();

        $this->fromIp(self::OTHER_IP)
            ->getJson('/api/users/me', ['Authorization' => 'Bearer not-a-real-token'])
            ->assertUnauthorized();
    }

    // --- What stays reachable from anywhere ---

    public function test_public_share_links_keep_working_from_outside_the_list(): void
    {
        $member = $this->user();
        $share = $this->share($member);
        $refreshToken = $this->refreshTokenOf($member);
        $this->enableAllowlist();

        $this->fromIp(self::OTHER_IP)->getJson("/api/shares/{$share->long_id}")->assertOk();
        // A member at home opening a link still holds their refresh cookie
        $this->fromIp(self::OTHER_IP)
            ->withUnencryptedCookie('refresh_token', $refreshToken)
            ->getJson("/api/shares/{$share->long_id}")
            ->assertOk();
    }

    public function test_reverse_share_guests_are_not_restricted(): void
    {
        $guest = $this->user(['is_guest' => true]);
        $headers = $this->bearer($guest);
        $refreshToken = auth('api')->tokenById($guest->id);
        $this->enableAllowlist();

        $this->fromIp(self::OTHER_IP)->getJson('/api/users/me', $headers)->assertOk();
        $this->fromIp(self::OTHER_IP)
            ->withUnencryptedCookie('refresh_token', $refreshToken)
            ->postJson('/api/auth/refresh')
            ->assertOk();
    }

    public function test_the_application_itself_still_loads_outside_the_list(): void
    {
        $this->enableAllowlist();

        $this->fromIp(self::OTHER_IP)->get('/')->assertOk();
        $this->fromIp(self::OTHER_IP)->getJson('/api/health')->assertOk();
    }

    // --- Administration ---

    public function test_an_admin_cannot_lock_themselves_out_by_enabling_the_list(): void
    {
        $admin = $this->user(['admin' => true]);

        $this->fromIp(self::OTHER_IP)
            ->putJson('/api/settings', ['settings' => [
                ['key' => 'application_name', 'value' => 'Changed'],
                ['key' => 'login_ip_allowlist_enabled', 'value' => 'true'],
                ['key' => 'login_ip_allowlist', 'value' => self::ALLOWED_IP],
            ]], $this->bearer($admin))
            ->assertStatus(422)
            ->assertJson(['code' => 'ip_allowlist_lockout', 'data' => ['ip' => self::OTHER_IP]]);

        // Nothing from the refused request is saved, whatever its position in it
        $this->assertSame('false', $this->storedValue('login_ip_allowlist_enabled'));
        $this->assertNotSame('Changed', $this->storedValue('application_name'));
    }

    public function test_an_admin_cannot_enable_an_empty_list(): void
    {
        $admin = $this->user(['admin' => true]);

        $this->fromIp(self::OTHER_IP)
            ->putJson('/api/settings', ['settings' => [
                ['key' => 'login_ip_allowlist_enabled', 'value' => 'true'],
                ['key' => 'login_ip_allowlist', 'value' => ''],
            ]], $this->bearer($admin))
            ->assertStatus(422)
            ->assertJson(['code' => 'ip_allowlist_lockout']);
    }

    public function test_enabling_alone_is_checked_against_the_stored_list(): void
    {
        $admin = $this->user(['admin' => true]);
        Setting::where('key', 'login_ip_allowlist')->update(['value' => self::ALLOWED_IP]);
        app(SettingsService::class)->clearCache();

        $this->fromIp(self::OTHER_IP)
            ->putJson('/api/settings', ['settings' => [['key' => 'login_ip_allowlist_enabled', 'value' => 'true']]], $this->bearer($admin))
            ->assertStatus(422)
            ->assertJson(['code' => 'ip_allowlist_lockout']);
    }

    public function test_an_admin_cannot_remove_their_own_address_from_an_enabled_list(): void
    {
        $admin = $this->user(['admin' => true]);
        $this->enableAllowlist();

        $this->fromIp(self::ALLOWED_IP)
            ->putJson('/api/settings', ['settings' => [['key' => 'login_ip_allowlist', 'value' => '10.0.0.0/8']]], $this->bearer($admin))
            ->assertStatus(422)
            ->assertJson(['code' => 'ip_allowlist_lockout']);

        $this->assertSame(self::ALLOWED_IP."\n10.0.0.0/8", $this->storedValue('login_ip_allowlist'));
    }

    public function test_an_admin_on_a_listed_address_can_enable_the_list(): void
    {
        $admin = $this->user(['admin' => true]);

        $this->fromIp(self::ALLOWED_IP)
            ->putJson('/api/settings', ['settings' => [
                ['key' => 'login_ip_allowlist_enabled', 'value' => 'true'],
                ['key' => 'login_ip_allowlist', 'value' => self::ALLOWED_IP],
            ]], $this->bearer($admin))
            ->assertOk();

        // Takes effect at once: the settings cache must not keep the old value
        $this->fromIp(self::OTHER_IP)
            ->postJson('/api/auth/login', ['email' => $admin->email, 'password' => 'password'])
            ->assertForbidden();
    }

    public function test_the_list_can_always_be_turned_off(): void
    {
        $admin = $this->user(['admin' => true]);
        $this->enableAllowlist('10.0.0.0/8');

        $this->fromIp('10.1.1.1')
            ->putJson('/api/settings', ['settings' => [
                ['key' => 'login_ip_allowlist_enabled', 'value' => 'false'],
                ['key' => 'login_ip_allowlist', 'value' => self::ALLOWED_IP],
            ]], $this->bearer($admin))
            ->assertOk();

        $this->assertSame('false', $this->storedValue('login_ip_allowlist_enabled'));
    }

    public function test_invalid_entries_are_refused_even_while_the_list_is_off(): void
    {
        $admin = $this->user(['admin' => true]);

        $this->putJson('/api/settings', ['settings' => [
            ['key' => 'login_ip_allowlist', 'value' => "127.0.0.1\nnot-an-ip"],
        ]], $this->bearer($admin))
            ->assertStatus(422)
            ->assertJson(['code' => 'ip_allowlist_invalid', 'data' => ['invalid' => ['not-an-ip']]]);
    }

    /**
     * The form sends '' on a first save, which reaches the database as NULL;
     * it then reloads null and sends it back as the string "null".
     */
    public function test_an_empty_list_from_the_settings_form_saves(): void
    {
        $admin = $this->user(['admin' => true]);

        foreach (['null', '', null] as $value) {
            $this->putJson('/api/settings', ['settings' => [
                ['key' => 'login_ip_allowlist_enabled', 'value' => 'false'],
                ['key' => 'login_ip_allowlist', 'value' => $value],
            ]], $this->bearer($admin))
                ->assertOk();
        }
    }

    public function test_other_settings_are_not_affected_by_the_list_checks(): void
    {
        $admin = $this->user(['admin' => true]);

        $this->fromIp(self::OTHER_IP)
            ->putJson('/api/settings', ['settings' => [['key' => 'application_name', 'value' => 'Changed']]], $this->bearer($admin))
            ->assertOk();

        $this->assertSame('Changed', $this->storedValue('application_name'));
    }

    public function test_the_list_is_hidden_from_members(): void
    {
        $this->enableAllowlist();
        $member = $this->user();

        foreach (['system.*', 'system.security'] as $group) {
            $response = $this->fromIp(self::ALLOWED_IP)
                ->getJson("/api/settings/group/{$group}", $this->bearer($member))
                ->assertOk();

            $this->assertStringNotContainsString('login_ip_allowlist', $response->getContent());
        }
        foreach (['login_ip_allowlist', 'login_ip_allowlist_enabled'] as $key) {
            $this->fromIp(self::ALLOWED_IP)->getJson("/api/settings/{$key}", $this->bearer($member))->assertNotFound();
        }
    }

    public function test_admins_read_the_list_and_the_address_the_application_sees(): void
    {
        $admin = $this->user(['admin' => true]);
        $this->enableAllowlist();

        $this->fromIp(self::ALLOWED_IP)->getJson('/api/settings/group/system.*', $this->bearer($admin))
            ->assertOk()
            ->assertJsonFragment(['key' => 'login_ip_allowlist', 'value' => self::ALLOWED_IP."\n10.0.0.0/8"]);
        $this->fromIp(self::ALLOWED_IP)->getJson('/api/settings/client-ip', $this->bearer($admin))
            ->assertOk()
            ->assertJsonPath('data.ip', self::ALLOWED_IP);
    }

    public function test_members_cannot_use_the_client_ip_route(): void
    {
        $this->getJson('/api/settings/client-ip', $this->bearer($this->user()))->assertForbidden();
    }

    public function test_the_recovery_command_turns_the_list_off_immediately(): void
    {
        $user = $this->user();
        $this->enableAllowlist();
        app(SettingsService::class)->get('login_ip_allowlist_enabled');

        $this->artisan('disable-ip-allowlist')->assertSuccessful();

        $this->assertSame('false', $this->storedValue('login_ip_allowlist_enabled'));
        $this->fromIp(self::OTHER_IP)
            ->postJson('/api/auth/login', ['email' => $user->email, 'password' => 'password'])
            ->assertOk();
    }
}
