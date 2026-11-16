<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

// tusd runs in the container and calls its hooks over the loopback, nothing else may: behind a
// reverse proxy every outside request comes from a private address, and "/api/tusd-hooks/" (a
// trailing slash) went past the block of Caddy to an app that took any private address. The
// address checked is the one of the connection, never a header a client writes.
class TusdHooksSourceTest extends TestCase
{
    use RefreshDatabase;

    private function hook(string $from, array $headers = [], string $path = '/api/tusd-hooks')
    {
        return $this->withServerVariables(['REMOTE_ADDR' => $from])->postJson($path, ['Type' => 'post-receive'], $headers);
    }

    public function test_tusd_on_the_loopback_reaches_its_hooks(): void
    {
        $this->hook('127.0.0.1')->assertOk()->assertJsonPath('ok', true);
        $this->hook('::1')->assertOk();
    }

    public function test_a_private_address_is_refused(): void
    {
        foreach (['172.18.0.5', '10.0.0.2', '192.168.1.20'] as $address) {
            $this->hook($address)->assertForbidden();
            $this->hook($address, [], '/api/tusd-hooks/')->assertForbidden();
        }
    }

    public function test_a_forwarded_loopback_address_is_refused(): void
    {
        config(['trustedproxy.proxies' => '*']);

        $this->hook('10.0.0.2', ['X-Forwarded-For' => '127.0.0.1'])->assertForbidden();
    }
}
