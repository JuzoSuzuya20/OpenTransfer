<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * The address the IP allowlist and the rate limits rely on. X-Forwarded-For can
 * be sent by anyone: it only counts when it comes from a configured proxy.
 */
class TrustedProxiesTest extends TestCase
{
    use RefreshDatabase;

    protected $seed = true;

    private const PROXY = '192.0.2.1';
    private const CLIENT = '203.0.113.50';

    protected function tearDown(): void
    {
        putenv('TRUSTED_PROXIES');
        parent::tearDown();
    }

    /**
     * Goes through the real configuration file, as TRUSTED_PROXIES is read
     * from the environment of the container.
     */
    private function configureTrustedProxies(?string $value): void
    {
        putenv($value === null ? 'TRUSTED_PROXIES' : "TRUSTED_PROXIES={$value}");
        config(['trustedproxy' => require config_path('trustedproxy.php')]);
    }

    private function ipSeenFrom(string $peer, string $forwardedFor, string $host = 'localhost'): ?string
    {
        $admin = User::factory()->create(['admin' => true, 'active' => true, 'is_guest' => false]);

        return $this->withServerVariables(['REMOTE_ADDR' => $peer])
            ->withHeaders([
                'Authorization' => 'Bearer '.auth('api')->tokenById($admin->id),
                'X-Forwarded-For' => $forwardedFor,
            ])
            // The host of an absolute URL becomes the Host header of the request
            ->getJson("http://{$host}/api/settings/client-ip")
            ->assertOk()
            ->json('data.ip');
    }

    public function test_the_header_is_ignored_when_no_proxy_is_configured(): void
    {
        $this->configureTrustedProxies(null);

        $this->assertSame(self::PROXY, $this->ipSeenFrom(self::PROXY, self::CLIENT));
    }

    /**
     * Laravel trusts everyone when no proxy is configured and the host ends
     * with one of these names: anyone can send such a Host header.
     */
    #[DataProvider('hostingHosts')]
    public function test_a_forged_host_does_not_turn_on_trust(string $host): void
    {
        $this->configureTrustedProxies(null);

        $this->assertSame(self::PROXY, $this->ipSeenFrom(self::PROXY, self::CLIENT, $host));
    }

    public static function hostingHosts(): array
    {
        return ['forge' => ['anything.on-forge.com'], 'vapor' => ['anything.on-vapor.com']];
    }

    #[DataProvider('proxyLists')]
    public function test_the_client_address_comes_from_a_configured_proxy(string $list): void
    {
        $this->configureTrustedProxies($list);

        $this->assertSame(self::CLIENT, $this->ipSeenFrom(self::PROXY, self::CLIENT));
    }

    public static function proxyLists(): array
    {
        return [
            'one address' => [self::PROXY],
            'a range' => ['192.0.2.0/24'],
            'several, with spaces' => ['10.0.0.1, 192.0.2.1'],
            'the direct peer' => ['*'],
        ];
    }

    public function test_the_header_is_ignored_from_another_address_than_the_proxy(): void
    {
        $this->configureTrustedProxies(self::PROXY);

        $this->assertSame('198.51.100.7', $this->ipSeenFrom('198.51.100.7', self::CLIENT));
    }

    /**
     * Proxies append to what the client sent: the address before the proxy is
     * the real one, whatever comes before it was written by the client.
     */
    #[DataProvider('proxyListsForForgedChain')]
    public function test_a_client_cannot_forge_its_address_through_the_proxy(string $list): void
    {
        $this->configureTrustedProxies($list);

        $this->assertSame(self::CLIENT, $this->ipSeenFrom(self::PROXY, '198.51.100.99, '.self::CLIENT));
    }

    public static function proxyListsForForgedChain(): array
    {
        return ['one address' => [self::PROXY], 'the direct peer' => ['*']];
    }

    /**
     * Declared at the identity providers: setting TRUSTED_PROXIES must change
     * the client address and nothing else.
     */
    public function test_a_proxy_cannot_change_the_sso_callback_url(): void
    {
        $this->configureTrustedProxies(self::PROXY);
        Route::middleware('web')->get('/_callback-url', fn () => route('social.provider.callback', ['provider' => 'uuid']));

        $this->withServerVariables(['REMOTE_ADDR' => self::PROXY])
            ->withHeaders([
                'X-Forwarded-Host' => 'evil.example',
                'X-Forwarded-Port' => '8443',
                'X-Forwarded-Proto' => 'https',
                'X-Forwarded-Prefix' => '/evil',
            ])
            ->get('http://share.example.test/_callback-url')
            ->assertOk()
            ->assertSee('http://share.example.test/auth/provider/uuid/callback', false);
    }
}
