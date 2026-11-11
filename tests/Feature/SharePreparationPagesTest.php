<?php

namespace Tests\Feature;

use App\Models\File;
use App\Models\Setting;
use App\Models\Share;
use App\Models\User;
use App\Services\SettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * What a recipient sees when the archive of a share is still being built, or could not be:
 * a page of the application, in the default language of the instance, that tells what to do.
 */
class SharePreparationPagesTest extends TestCase
{
    use RefreshDatabase;

    protected $seed = true;

    protected function setUp(): void
    {
        parent::setUp();
        Setting::where('key', 'default_language')->update(['value' => 'fr']);
        app(SettingsService::class)->clearCache();
    }

    private function share(string $status, ?string $password = null): Share
    {
        $owner = User::factory()->create(['name' => 'Camille Martin']);
        $share = Share::create([
            'user_id' => $owner->id, 'name' => 'Archives du service technique', 'path' => 'tests-' . Str::random(8),
            'long_id' => 'test-' . Str::lower(Str::random(12)), 'size' => 20, 'file_count' => 2, 'download_count' => 0,
            'expires_at' => now()->addDays(3), 'status' => $status, 'password' => $password ? Hash::make($password) : null,
        ]);
        foreach (['releves.zip', 'plans.dwg'] as $name) {
            File::create(['name' => $name, 'original_name' => $name, 'size' => 10, 'type' => 'application/zip', 'share_id' => $share->id]);
        }

        return $share;
    }

    public function test_a_share_being_prepared_says_so_and_reloads_until_the_archive_is_ready(): void
    {
        $share = $this->share('pending');

        $response = $this->get("/api/shares/{$share->long_id}/download");

        $response->assertOk();
        $response->assertSee('<html lang="fr">', false);
        $response->assertSee("Préparation de l'archive");
        $response->assertSee('Archives du service technique');
        $response->assertSee('http-equiv="refresh"', false);
        $response->assertDontSee('Share is being processed');
    }

    public function test_a_protected_share_being_prepared_sends_back_to_its_page_instead_of_reloading(): void
    {
        $share = $this->share('pending', 'secret-pass');

        $response = $this->post("/api/shares/{$share->long_id}/download", ['password' => 'secret-pass']);

        $response->assertOk();
        $response->assertSee("Préparation de l'archive");
        // A reload would come back without the password
        $response->assertDontSee('http-equiv="refresh"', false);
        $response->assertSee('href="/shares/' . $share->long_id . '"', false);
    }

    public function test_a_failed_share_says_the_download_is_not_available_and_what_to_do(): void
    {
        $share = $this->share('failed');

        $response = $this->get("/api/shares/{$share->long_id}/download");

        $response->assertOk();
        $response->assertSee('Téléchargement impossible');
        $response->assertSee("Demandez à l'expéditeur");
        $response->assertSee('href="/shares/' . $share->long_id . '"', false);
        $response->assertDontSee('Bad Error');
        $response->assertDontSee('horribly');
    }

    public function test_the_pages_follow_the_default_language_of_the_instance(): void
    {
        Setting::where('key', 'default_language')->update(['value' => 'es']);
        app(SettingsService::class)->clearCache();

        $response = $this->get('/api/shares/' . $this->share('failed')->long_id . '/download');

        $response->assertSee('<html lang="es">', false);
        $response->assertSee('Descarga no disponible');
    }

    public function test_the_pages_load_the_styles_of_the_application_but_not_the_application(): void
    {
        $manifest = json_decode(file_get_contents(public_path('build/manifest.json')), true);

        $html = $this->get('/api/shares/' . $this->share('pending')->long_id . '/download')->getContent();

        $this->assertStringNotContainsString($manifest['resources/js/app.js']['file'], $html);
        $this->assertStringContainsString($manifest['resources/js/status.js']['file'], $html);
    }
}
