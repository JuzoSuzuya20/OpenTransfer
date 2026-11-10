<?php

namespace Tests\Feature;

use App\Models\Theme;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

// The page tells the front end two things it cannot find out by itself: whether the logo is
// still a default one (the header then draws the OpenTransfer mark in the colours of the
// theme), and whether the theme brings its own page background (no default image over it).
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

    public function test_a_theme_with_its_own_page_background_says_so(): void
    {
        $this->assertTrue($this->pageSetting('theme_page_background'));
    }

    public function test_a_theme_without_page_background_keeps_the_default_image(): void
    {
        Theme::query()->update(['active' => false]);
        Theme::where('name', 'Sakura')->update(['active' => true]);

        $this->assertFalse($this->pageSetting('theme_page_background'));
    }
}
