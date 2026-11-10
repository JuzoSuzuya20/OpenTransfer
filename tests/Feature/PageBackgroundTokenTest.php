<?php

namespace Tests\Feature;

use App\Models\Theme;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

// A theme may give the page its own background, distinct from the one of the panels.
// Themes written before that token existed must keep rendering as they did.
class PageBackgroundTokenTest extends TestCase
{
    use RefreshDatabase;

    protected $seed = true;

    public function test_the_opentransfer_theme_gives_the_page_its_own_background(): void
    {
        $this->get('/')
            ->assertOk()
            ->assertSee('--page-background-color: #161616;', false);
    }

    public function test_a_theme_without_a_page_background_does_not_emit_the_variable(): void
    {
        Theme::query()->update(['active' => false]);
        Theme::where('name', 'Sakura')->update(['active' => true]);

        $this->get('/')
            ->assertOk()
            ->assertDontSee('--page-background-color', false);
    }

    public function test_the_page_background_of_a_custom_theme_reaches_the_page(): void
    {
        $theme = Theme::where('name', 'Sakura')->firstOrFail();
        $json = json_decode($theme->getRawOriginal('theme'), true);
        $json['page'] = ['background' => '#fdf2f8'];

        Theme::query()->update(['active' => false]);
        $theme->update(['theme' => $json, 'active' => true]);

        $this->get('/')
            ->assertOk()
            ->assertSee('--page-background-color: #fdf2f8;', false);
    }

    public function test_the_opentransfer_theme_sets_the_colour_of_its_headings(): void
    {
        $this->get('/')
            ->assertOk()
            ->assertSee('--heading-color: #ffffff;', false);
    }

    public function test_a_theme_without_heading_colour_does_not_emit_the_variable(): void
    {
        Theme::query()->update(['active' => false]);
        Theme::where('name', 'Sakura')->update(['active' => true]);

        $this->get('/')
            ->assertOk()
            ->assertDontSee('--heading-color', false);
    }
}
