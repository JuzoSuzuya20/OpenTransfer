<?php

namespace Tests\Feature;

use App\Models\Theme;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

// OpenTransfer has one visual identity, dark or light after the system setting of the visitor.
// The themes an Erugo instance stored stay in its database, but no page takes its colours from them.
class FixedIdentityTest extends TestCase
{
    use RefreshDatabase;

    protected $seed = true;

    // A theme as an instance moved from Erugo holds it, active
    private function storedTheme(): Theme
    {
        return Theme::create([
            'name' => 'Sakura',
            'category' => 'light',
            'active' => true,
            'theme' => ['panel' => ['background' => 'rgb(255, 240, 245)'], 'buttons' => ['primary' => ['default' => ['background' => 'rgb(219, 112, 147)']]]],
        ]);
    }

    public static function pages(): array
    {
        return [['/'], ['/reset-password/some-token'], ['/shares/some-share']];
    }

    #[DataProvider('pages')]
    public function test_the_page_carries_no_theme_colours(string $path): void
    {
        $this->storedTheme();

        $this->get($path)
            ->assertOk()
            ->assertDontSee('--panel-background-color', false)
            ->assertDontSee('rgb(219, 112, 147)', false);
    }

    public function test_the_stored_themes_are_kept(): void
    {
        $theme = $this->storedTheme();

        Artisan::call('db:seed', ['--force' => true]);

        $this->assertNotNull(Theme::find($theme->id));
    }
}
