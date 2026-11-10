<?php

namespace Tests\Feature;

use App\Models\Theme;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

// The seeder runs at every start of the container: it must give a fresh install the
// OpenTransfer theme, and leave alone the choice made on an existing instance.
class DefaultThemeTest extends TestCase
{
    use RefreshDatabase;

    protected $seed = true;

    private function activeNames(): array
    {
        return Theme::where('active', true)->pluck('name')->all();
    }

    public function test_a_fresh_install_starts_on_the_opentransfer_theme(): void
    {
        $this->assertSame(['OpenTransfer'], $this->activeNames());
    }

    public function test_the_theme_is_bundled_and_listed_with_the_dark_ones(): void
    {
        $theme = Theme::where('name', 'OpenTransfer')->firstOrFail();

        $this->assertSame('Dark', $theme->category);
        $this->assertSame(1, (int) $theme->bundled);
    }

    public function test_an_instance_that_already_chose_a_theme_keeps_it(): void
    {
        Theme::query()->update(['active' => false]);
        Theme::where('name', 'Sakura')->update(['active' => true]);

        $this->seed(DatabaseSeeder::class);

        $this->assertSame(['Sakura'], $this->activeNames());
    }

    public function test_an_instance_still_on_the_former_default_keeps_it(): void
    {
        Theme::query()->update(['active' => false]);
        Theme::where('name', 'Erugo 2026')->update(['active' => true]);

        $this->seed(DatabaseSeeder::class);

        $this->assertSame(['Erugo 2026'], $this->activeNames());
    }

    public function test_the_colours_are_those_of_the_design(): void
    {
        $theme = Theme::where('name', 'OpenTransfer')->firstOrFail()->theme;

        $this->assertSame('#6a6af4', $theme->buttons->primary->default->background);
        $this->assertSame('#1e1e1e', $theme->panel->background);
        $this->assertSame('#cecece', $theme->panel->text);
        $this->assertSame('6px', $theme->dimensions->button->borderRadius);
    }

    // The model rewrites any value it does not recognise: nothing of this theme may be altered
    public function test_every_value_survives_the_sanitizer_of_the_model(): void
    {
        $theme = Theme::where('name', 'OpenTransfer')->firstOrFail();

        $this->assertEquals(json_decode($theme->getRawOriginal('theme')), $theme->theme);
    }
}
