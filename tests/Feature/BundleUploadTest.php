<?php

namespace Tests\Feature;

use App\Models\Setting;
use App\Models\UploadSession;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Les dossiers sont envoyés en une archive (bundle) extraite côté serveur.
 * Une petite archive très compressée peut se décompresser en dizaines de Go :
 * sa taille décompressée est contrôlée avant toute extraction.
 */
class BundleUploadTest extends TestCase
{
    use RefreshDatabase;

    protected $seed = true;

    private string $uploadId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->uploadId = bin2hex(random_bytes(16));
        File::ensureDirectoryExists(storage_path('app/uploads'));
    }

    protected function tearDown(): void
    {
        File::delete(storage_path("app/uploads/{$this->uploadId}"));
        File::deleteDirectory(storage_path("app/uploads/{$this->uploadId}_extracted"));
        parent::tearDown();
    }

    /** Archive comme celle du front : un manifeste et les fichiers du dossier. */
    private function makeBundle(array $files): void
    {
        $zip = new \ZipArchive();
        $zip->open(storage_path("app/uploads/{$this->uploadId}"), \ZipArchive::CREATE | \ZipArchive::OVERWRITE);
        $manifest = ['files' => []];
        foreach ($files as $path => $content) {
            $zip->addFromString($path, $content);
            $manifest['files'][] = ['path' => $path, 'originalName' => basename($path), 'size' => strlen($content), 'type' => 'text/plain'];
        }
        $zip->addFromString('__erugo_manifest__.json', json_encode($manifest));
        $zip->close();
    }

    private function finishBundleUpload(): UploadSession
    {
        $user = User::factory()->create(['admin' => false, 'active' => true, 'is_guest' => false]);
        $session = UploadSession::create([
            'upload_id' => $this->uploadId, 'user_id' => $user->id, 'filename' => 'folder.zip',
            'filesize' => 1, 'filetype' => 'application/zip', 'total_chunks' => 1, 'status' => 'pending',
        ]);

        $this->postJson('/api/tusd-hooks', [
            'Type' => 'post-finish',
            'Event' => ['Upload' => [
                'ID' => $this->uploadId,
                'Size' => filesize(storage_path("app/uploads/{$this->uploadId}")),
                'MetaData' => ['filename' => 'folder.zip', 'filetype' => 'application/zip', 'isBundle' => 'true'],
            ]],
        ])->assertOk();

        return $session->fresh();
    }

    public function test_a_regular_folder_bundle_is_extracted(): void
    {
        $this->makeBundle(['docs/a.txt' => 'hello', 'docs/sub/b.txt' => 'world']);

        $session = $this->finishBundleUpload();

        $this->assertSame('complete', $session->status);
        $this->assertCount(2, json_decode($session->bundle_file_ids, true));
        $this->assertFileExists(storage_path("app/uploads/{$this->uploadId}_extracted/docs/sub/b.txt"));
    }

    public function test_a_bundle_that_expands_beyond_the_max_share_size_is_refused_before_extraction(): void
    {
        Setting::where('key', 'max_share_size')->update(['value' => '1']);
        Setting::where('key', 'max_share_size_unit')->update(['value' => 'MB']);
        app(\App\Services\SettingsService::class)->clearCache();
        // 20 Mo de zéros : quelques Ko une fois compressés
        $this->makeBundle(['bomb.txt' => str_repeat("\0", 20 * 1024 * 1024)]);
        $this->assertLessThan(1024 * 1024, filesize(storage_path("app/uploads/{$this->uploadId}")));

        $session = $this->finishBundleUpload();

        $this->assertSame('failed', $session->status);
        $this->assertFileDoesNotExist(storage_path("app/uploads/{$this->uploadId}_extracted/bomb.txt"));
    }
}
