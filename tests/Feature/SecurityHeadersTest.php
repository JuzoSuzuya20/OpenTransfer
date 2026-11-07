<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * En-têtes de défense en profondeur, inspirés du fork moutonnoireu/erugo-security.
 */
class SecurityHeadersTest extends TestCase
{
    use RefreshDatabase;

    protected $seed = true;

    public function test_pages_forbid_inline_and_third_party_scripts(): void
    {
        $csp = $this->get('/')->assertOk()->headers->get('Content-Security-Policy');

        $this->assertNotNull($csp);
        $this->assertMatchesRegularExpression("/script-src 'self'(;|$)/", $csp);
        $this->assertStringContainsString("object-src 'none'", $csp);
        $this->assertStringContainsString("frame-ancestors 'self'", $csp);
        $this->assertStringContainsString("base-uri 'self'", $csp);
        $this->assertStringNotContainsString('googleapis', $csp);
    }

    public function test_share_links_never_leak_through_the_referer(): void
    {
        // Les identifiants de partage sont dans l'URL : aucun site tiers ne doit les recevoir
        $this->get('/')->assertHeader('Referrer-Policy', 'no-referrer');
    }

    public function test_browser_features_and_window_isolation_are_restricted(): void
    {
        $response = $this->get('/');

        $response->assertHeader('Cross-Origin-Opener-Policy', 'same-origin');
        $response->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->assertStringContainsString('camera=()', $response->headers->get('Permissions-Policy'));
    }

    public function test_api_responses_carry_the_headers_too(): void
    {
        $this->getJson('/api/available-auth-providers')->assertHeader('Referrer-Policy', 'no-referrer');
    }

    public function test_svg_favicon_keeps_its_stricter_policy(): void
    {
        $csp = $this->get('/api/favicon')->headers->get('Content-Security-Policy');

        $this->assertStringStartsWith("default-src 'none'", $csp);
    }

    public function test_pages_load_nothing_from_google_or_cdns(): void
    {
        $html = $this->get('/')->getContent();

        $this->assertStringNotContainsString('fonts.googleapis.com', $html);
        $this->assertStringNotContainsString('cdnjs.cloudflare.com', $html);
    }
}
