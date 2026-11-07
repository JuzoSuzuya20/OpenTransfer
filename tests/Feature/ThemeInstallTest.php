<?php

namespace Tests\Feature;

use App\Models\Theme;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

class ThemeInstallTest extends TestCase
{
    use RefreshDatabase;

    protected $seed = true;

    private function admin(): User
    {
        return User::factory()->create(['admin' => true, 'active' => true, 'is_guest' => false]);
    }

    public function test_a_valid_theme_file_is_installed(): void
    {
        $file = UploadedFile::fake()->createWithContent('theme.json', file_get_contents(resource_path('js/themes/default.json')));

        $this->actingAs($this->admin(), 'api')->post('/api/themes/install', ['name' => 'Maison', 'file' => $file])->assertOk();

        $this->assertDatabaseHas('themes', ['name' => 'Maison']);
    }

    public function test_a_file_that_is_not_a_theme_is_refused(): void
    {
        $file = UploadedFile::fake()->createWithContent('theme.json', '"just a string"');

        $this->actingAs($this->admin(), 'api')->postJson('/api/themes/install', ['name' => 'Cassé', 'file' => $file])->assertStatus(422);

        $this->assertSame(0, Theme::where('name', 'Cassé')->count());
    }

    public function test_an_oversized_theme_file_is_refused(): void
    {
        $file = UploadedFile::fake()->create('theme.json', 600);

        $this->actingAs($this->admin(), 'api')->postJson('/api/themes/install', ['name' => 'Énorme', 'file' => $file])->assertStatus(422);
    }
}
