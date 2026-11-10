<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

// The page tells the front end what it cannot find out by itself: whether the logo is
// still a default one (the header then draws the OpenTransfer mark).
class ShellBrandingTest extends TestCase
{
    use RefreshDatabase;

    protected $seed = true;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
    }

    private function pageSetting(string $key): mixed
    {
        $html = $this->get('/')->assertOk()->getContent();
        $this->assertSame(1, preg_match("/data-settings='([^']*)'/", $html, $match));

        return json_decode(htmlspecialchars_decode($match[1]), true)[$key] ?? null;
    }

    public function test_the_logo_shipped_with_opentransfer_counts_as_default(): void
    {
        Storage::disk('public')->put(
            'images/logo.png',
            file_get_contents(base_path('storage_skell/app/public/images/logo.png'))
        );

        $this->assertTrue($this->pageSetting('default_logo'));
    }

    public function test_the_logo_an_erugo_instance_never_changed_counts_as_default(): void
    {
        Storage::disk('public')->put(
            'images/logo.png',
            file_get_contents(base_path('tests/fixtures/erugo-default-logo.png'))
        );

        $this->assertTrue($this->pageSetting('default_logo'));
    }

    public function test_an_uploaded_logo_is_not_a_default_one(): void
    {
        Storage::disk('public')->put('images/logo.png', 'the logo of the organisation');

        $this->assertFalse($this->pageSetting('default_logo'));
    }

    public function test_a_missing_logo_falls_back_on_the_default_mark(): void
    {
        $this->assertTrue($this->pageSetting('default_logo'));
    }
}
