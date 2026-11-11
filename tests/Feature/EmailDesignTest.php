<?php

namespace Tests\Feature;

use App\Mail\passwordResetMail;
use App\Mail\shareCreatedMail;
use App\Models\File;
use App\Models\Setting;
use App\Models\Share;
use App\Models\User;
use App\Services\SettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * The emails look like the application: its typeface, its mark (or the logo of the instance),
 * and a share announced the way its download page shows it.
 */
class EmailDesignTest extends TestCase
{
    use RefreshDatabase;

    protected $seed = true;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
        $this->setting('application_url', 'https://files.example.test');
        $this->setting('default_language', 'fr');
    }

    private function setting(string $key, string $value): void
    {
        Setting::updateOrCreate(['key' => $key], ['value' => $value]);
        app(SettingsService::class)->clearCache();
    }

    private function shareEmail(?string $message = null): string
    {
        $owner = User::factory()->create(['name' => 'Camille Martin', 'admin' => false, 'active' => true, 'is_guest' => false]);
        $share = Share::create([
            'user_id' => $owner->id, 'name' => 'Dossier marché travaux', 'description' => $message, 'path' => 'tests-'.Str::random(8),
            'long_id' => 'test-'.Str::lower(Str::random(12)), 'size' => 641728512, 'file_count' => 2, 'download_count' => 0,
            'expires_at' => now()->setDate(2026, 11, 23), 'status' => 'ready',
        ]);
        foreach (['plans.pdf', 'photos.zip'] as $name) {
            File::create(['name' => $name, 'original_name' => $name, 'size' => 1, 'type' => 'application/pdf', 'share_id' => $share->id]);
        }

        return (new shareCreatedMail($owner, $share->fresh(), ['name' => 'Dominique Durand', 'email' => 'dominique@example.test']))->render();
    }

    public function test_the_email_loads_the_typeface_of_the_application_from_the_instance_itself(): void
    {
        $html = $this->shareEmail();

        $this->assertStringContainsString('@font-face', $html);
        $this->assertStringContainsString("url('https://files.example.test/build/email/plus-jakarta-sans-latin-wght-normal.woff2')", $html);
        $this->assertStringContainsString("url('https://files.example.test/build/email/plus-jakarta-sans-latin-ext-wght-normal.woff2')", $html);
        // Not from a third party: the reader's address would leave for it at every opening
        $this->assertStringNotContainsString('fonts.googleapis', $html);
        $this->assertStringNotContainsString('fonts.gstatic', $html);
    }

    public function test_the_header_shows_the_mark_of_the_application_while_the_logo_is_the_shipped_one(): void
    {
        $html = (new passwordResetMail('token', User::factory()->create()))->render();

        $this->assertStringContainsString('src="https://files.example.test/build/email/brand-mark.png"', $html);
        $this->assertStringNotContainsString('/images/logo.png', $html);
    }

    public function test_the_header_shows_the_logo_of_the_instance_once_an_admin_changed_it(): void
    {
        Storage::disk('public')->put('images/logo.png', 'logo of the organisation');
        $this->setting('logo_width', '150');

        $html = (new passwordResetMail('token', User::factory()->create()))->render();

        $this->assertStringContainsString('src="https://files.example.test/images/logo.png"', $html);
        $this->assertStringContainsString('width="150"', $html);
        $this->assertStringNotContainsString('brand-mark.png', $html);
    }

    public function test_a_share_is_announced_as_its_download_page_shows_it(): void
    {
        $html = $this->shareEmail("Bonjour,\nvoici les pièces.");

        $this->assertStringContainsString('Camille vous a envoyé', $html);
        $this->assertMatchesRegularExpression('/<h2[^>]*>Dossier marché travaux<\/h2>/u', $html);
        $this->assertStringContainsString('Éléments', $html);
        $this->assertStringContainsString('Taille', $html);
        $this->assertStringContainsString('612.00 MB', $html);
        $this->assertStringContainsString('23/11/2026', $html);
        $this->assertStringContainsString('Message de Camille', $html);
        $this->assertStringContainsString('voici les pièces.', $html);
    }

    public function test_no_part_of_an_email_is_set_off_by_a_coloured_side_border(): void
    {
        $html = $this->shareEmail('Un message');

        $this->assertDoesNotMatchRegularExpression('/border-left:\s*[2-9]px/', $html);
    }
}
