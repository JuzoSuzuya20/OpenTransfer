<?php

namespace Tests\Feature;

use App\Models\File;
use App\Models\Share;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use App\Jobs\sendEmail;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Ce qui protège un partage : expiration, limite de téléchargements et mot
 * de passe. Une régression ici exposerait des fichiers.
 */
class ShareAccessTest extends TestCase
{
    use RefreshDatabase;

    protected $seed = true;

    private array $createdDirectories = [];

    protected function setUp(): void
    {
        parent::setUp();
        // Le mail « partage téléchargé » part en file d'attente en production.
        Queue::fake();
    }

    protected function tearDown(): void
    {
        foreach ($this->createdDirectories as $directory) {
            @unlink($directory.'/report.pdf');
            @rmdir($directory);
        }
        parent::tearDown();
    }

    /** Partage d'un seul fichier, présent sur le disque comme après un upload. */
    private function makeShare(array $attributes = []): Share
    {
        $owner = User::factory()->create(['admin' => false, 'active' => true, 'is_guest' => false]);
        $path = 'tests-'.Str::random(12);

        $share = Share::create(array_merge([
            'user_id' => $owner->id,
            'name' => 'Quarterly report',
            'path' => $path,
            'long_id' => 'test-'.Str::lower(Str::random(16)),
            'size' => 11,
            'file_count' => 1,
            'download_limit' => null,
            'download_count' => 0,
            'expires_at' => now()->addDays(7),
            'status' => 'ready',
        ], $attributes));

        File::create(['name' => 'report.pdf', 'original_name' => 'report.pdf', 'size' => 11, 'type' => 'application/pdf', 'share_id' => $share->id]);

        $directory = storage_path('app/shares/'.$path);
        @mkdir($directory, 0775, true);
        file_put_contents($directory.'/report.pdf', 'pdf-content');
        $this->createdDirectories[] = $directory;

        return $share->fresh();
    }

    public function test_an_unknown_share_is_not_found(): void
    {
        $this->getJson('/api/shares/does-not-exist')->assertNotFound();
    }

    public function test_an_active_share_can_be_read_and_downloaded(): void
    {
        $share = $this->makeShare();

        $this->getJson("/api/shares/{$share->long_id}")
            ->assertOk()
            ->assertJsonPath('data.share.name', 'Quarterly report')
            ->assertJsonPath('data.share.password_protected', false);

        $this->get("/api/shares/{$share->long_id}/download")->assertOk()->assertDownload('report.pdf');
        $this->assertSame(1, $share->fresh()->download_count);
        Queue::assertPushed(sendEmail::class);
    }

    public function test_an_expired_share_cannot_be_read_or_downloaded(): void
    {
        $share = $this->makeShare(['expires_at' => now()->subMinute()]);

        $this->getJson("/api/shares/{$share->long_id}")->assertStatus(410);
        $this->get("/api/shares/{$share->long_id}/download")->assertRedirect("/shares/{$share->long_id}");
        $this->assertSame(0, $share->fresh()->download_count);
    }

    public function test_a_share_stops_serving_files_once_its_download_limit_is_reached(): void
    {
        $share = $this->makeShare(['download_limit' => 1]);

        $this->get("/api/shares/{$share->long_id}/download")->assertOk();

        $this->getJson("/api/shares/{$share->long_id}")->assertStatus(410);
        $this->get("/api/shares/{$share->long_id}/download")->assertRedirect("/shares/{$share->long_id}");
        $this->assertSame(1, $share->fresh()->download_count);
    }

    public function test_a_password_protected_share_requires_the_right_password(): void
    {
        $share = $this->makeShare(['password' => Hash::make('s3cret-pass')]);

        $this->get("/api/shares/{$share->long_id}/download")
            ->assertRedirect("/shares/{$share->long_id}?error=password_required");
        $this->post("/api/shares/{$share->long_id}/download", ['password' => 'wrong'])
            ->assertRedirect("/shares/{$share->long_id}?error=invalid_password");
        $this->assertSame(0, $share->fresh()->download_count);

        $this->post("/api/shares/{$share->long_id}/download", ['password' => 's3cret-pass'])
            ->assertOk()
            ->assertDownload('report.pdf');
    }

    public function test_single_file_download_also_enforces_the_password(): void
    {
        $share = $this->makeShare(['password' => Hash::make('s3cret-pass')]);

        $this->getJson("/api/shares/{$share->long_id}/download/file/report.pdf")->assertUnauthorized();
        $this->getJson("/api/shares/{$share->long_id}/download/file/report.pdf?password=wrong")->assertUnauthorized();
        $this->get("/api/shares/{$share->long_id}/download/file/report.pdf?password=s3cret-pass")->assertOk();
    }

    public function test_the_share_password_hash_is_never_exposed(): void
    {
        $share = $this->makeShare(['password' => Hash::make('s3cret-pass')]);

        $response = $this->getJson("/api/shares/{$share->long_id}")->assertOk();

        $response->assertJsonPath('data.share.password_protected', true);
        $this->assertStringNotContainsString($share->password, $response->getContent());
    }
}
