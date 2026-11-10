<?php

namespace Tests\Feature;

use App\Models\Theme;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

// OpenTransfer has one visual identity, dark or light after the system setting of the visitor.
// The themes an Erugo instance stored stay in its database, but no page takes its colours from them.
class FixedIdentityTest extends TestCase
{
    use RefreshDatabase;

    protected $seed = true;

    public static function pages(): array
    {
        return [['/'], ['/reset-password/some-token'], ['/shares/some-share']];
    }

    #[DataProvider('pages')]
    public function test_the_page_carries_no_theme_colours(string $path): void
    {
        Theme::query()->update(['active' => false]);
        Theme::where('name', 'Sakura')->update(['active' => true]);

        $this->get($path)
            ->assertOk()
            ->assertDontSee('--panel-background-color', false)
            ->assertDontSee('--primary-button-background-color', false);
    }

    public function test_the_stored_themes_are_kept(): void
    {
        $this->assertGreaterThan(10, Theme::count());
    }
}
