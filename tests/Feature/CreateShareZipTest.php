<?php

namespace Tests\Feature;

use App\Jobs\CreateShareZip;
use App\Models\Share;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Les partages de plusieurs fichiers sont archivés en tâche de fond. L'archive
 * est faite en PHP (ZipArchive) plutôt qu'en lançant `zip`, et ne suit jamais
 * un lien qui sortirait du dossier du partage.
 */
class CreateShareZipTest extends TestCase
{
    use RefreshDatabase;

    protected $seed = true;

    private string $base;
    private string $outside;

    protected function tearDown(): void
    {
        File::deleteDirectory($this->base ?? '');
        File::deleteDirectory($this->outside ?? '');
        parent::tearDown();
    }

    private function share(): Share
    {
        $owner = User::factory()->create(['admin' => false, 'active' => true, 'is_guest' => false]);
        $longId = 'test-' . Str::lower(Str::random(12));
        $this->base = storage_path("app/shares/{$owner->id}");

        $share = Share::create([
            'user_id' => $owner->id, 'name' => 'Dossier', 'path' => "{$owner->id}/{$longId}", 'long_id' => $longId,
            'size' => 10, 'file_count' => 2, 'expires_at' => now()->addDay(), 'status' => 'pending',
        ]);

        $dir = "{$this->base}/{$longId}";
        File::ensureDirectoryExists("{$dir}/plans");
        File::put("{$dir}/cctp.txt", 'cctp');
        File::put("{$dir}/plans/rdc.txt", 'rdc');

        return $share;
    }

    public function test_a_multi_file_share_is_zipped_with_its_folders(): void
    {
        $share = $this->share();

        (new CreateShareZip($share))->handle();

        $zip = new \ZipArchive();
        $this->assertTrue($zip->open("{$this->base}/{$share->long_id}.zip"));
        $this->assertSame('cctp', $zip->getFromName('cctp.txt'));
        $this->assertSame('rdc', $zip->getFromName('plans/rdc.txt'));
        $zip->close();
        $this->assertSame('ready', $share->fresh()->status);
        $this->assertDirectoryDoesNotExist("{$this->base}/{$share->long_id}");
    }

    public function test_a_link_out_of_the_share_is_neither_zipped_nor_followed(): void
    {
        $share = $this->share();
        $this->outside = storage_path('framework/testing/outside-' . Str::random(8));
        File::ensureDirectoryExists($this->outside);
        File::put("{$this->outside}/secret.txt", 'secret');
        symlink($this->outside, "{$this->base}/{$share->long_id}/escape");

        (new CreateShareZip($share))->handle();

        $zip = new \ZipArchive();
        $zip->open("{$this->base}/{$share->long_id}.zip");
        $this->assertFalse($zip->getFromName('escape/secret.txt'));
        $zip->close();
        // Le nettoyage du dossier source ne doit pas vider la cible du lien
        $this->assertFileExists("{$this->outside}/secret.txt");
    }
}
