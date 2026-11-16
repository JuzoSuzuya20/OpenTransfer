<?php

namespace Tests\Feature;

use App\Models\Setting;
use App\Support\DefaultLogo;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

// The logo is a square mark, without a word on it: the name next to it is the one the admin
// chose ("Drop Me"). An instance that kept the logo it was shipped with (the banner of OpenTransfer
// or of Erugo) gets the square one; a logo an admin put in its place stays as it is.
class SquareLogoTest extends TestCase
{
    use RefreshDatabase;

    protected $seed = true;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
    }

    private function shipped(): string
    {
        return file_get_contents(base_path('storage_skell/app/public/images/_default-logo.png'));
    }

    public function test_the_logo_shipped_is_square(): void
    {
        foreach (['logo.png', '_default-logo.png'] as $file) {
            [$width, $height] = getimagesize(base_path("storage_skell/app/public/images/{$file}"));
            $this->assertSame($width, $height, $file);
            $this->assertGreaterThanOrEqual(256, $width, $file);
        }
    }

    public function test_a_banner_shipped_before_gives_way_to_the_square_logo(): void
    {
        foreach (['opentransfer-wide-logo.png', 'erugo-default-logo.png'] as $banner) {
            Storage::disk('public')->put('images/logo.png', file_get_contents(base_path("tests/fixtures/{$banner}")));
            Storage::disk('public')->put('images/_default-logo.png', file_get_contents(base_path("tests/fixtures/{$banner}")));

            DefaultLogo::refresh();

            $this->assertSame($this->shipped(), Storage::disk('public')->get('images/logo.png'), $banner);
            $this->assertSame($this->shipped(), Storage::disk('public')->get('images/_default-logo.png'), $banner);
            $this->assertTrue(usesDefaultLogo());
        }
    }

    public function test_a_logo_an_admin_put_stays(): void
    {
        Storage::disk('public')->put('images/logo.png', 'the logo of the organisation');

        DefaultLogo::refresh();

        $this->assertSame('the logo of the organisation', Storage::disk('public')->get('images/logo.png'));
        $this->assertFalse(usesDefaultLogo());
    }

    public function test_the_name_of_the_logo_of_erugo_is_gone(): void
    {
        Setting::where('key', 'logo')->update(['value' => 'erugo-logo.png']);

        DefaultLogo::refresh();

        $this->assertSame('logo.png', Setting::where('key', 'logo')->value('value'));
    }
}
