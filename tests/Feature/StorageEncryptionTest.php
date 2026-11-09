<?php

namespace Tests\Feature;

use App\Jobs\CreateShareZip;
use App\Models\File as ShareFile;
use App\Models\Setting;
use App\Models\Share;
use App\Models\User;
use App\Services\SettingsService;
use App\Services\StorageEncryption;
use App\Services\ZipEntryReader;
use Database\Seeders\SettingsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

/**
 * Optional encryption at rest of shared files, adapted from the
 * moutonnoireu/erugo-security fork. Off by default, and a share must keep
 * working through every change of this setting.
 */
class StorageEncryptionTest extends TestCase
{
    use RefreshDatabase;

    protected $seed = true;

    private const MIB = 1048576;

    private string $key;
    private string $dir;
    private array $created = [];

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        $this->key = base64_encode(str_repeat('k', 32));
        $this->dir = storage_path('framework/testing/encryption-'.Str::random(8));
        File::ensureDirectoryExists($this->dir);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->dir);
        foreach ($this->created as $path) {
            File::deleteDirectory($path);
        }
        parent::tearDown();
    }

    private function configureKey(?string $key = null): void
    {
        config(['app.storage_encryption_key' => $key ?? $this->key]);
    }

    private function enable(): void
    {
        $this->configureKey();
        Setting::where('key', 'storage_encryption_enabled')->update(['value' => 'true']);
        app(SettingsService::class)->clearCache();
    }

    private function encryption(): StorageEncryption
    {
        return app(StorageEncryption::class);
    }

    private function file(string $content): string
    {
        $path = $this->dir.'/'.Str::random(8);
        file_put_contents($path, $content);

        return $path;
    }

    private function owner(array $attributes = []): User
    {
        return User::factory()->create(array_merge(['admin' => false, 'active' => true, 'is_guest' => false], $attributes));
    }

    private function bearer(User $user): array
    {
        return ['Authorization' => 'Bearer '.auth('api')->tokenById($user->id)];
    }

    /**
     * A share as it stands after an upload, before the job that finishes it.
     *
     * @param array<string, string> $files relative path => content
     */
    private function share(array $files): Share
    {
        $owner = $this->owner();
        $longId = 'test-'.Str::lower(Str::random(16));
        $base = storage_path("app/shares/{$owner->id}");
        $this->created[] = $base;

        $share = Share::create([
            'user_id' => $owner->id, 'name' => 'Report', 'path' => "{$owner->id}/{$longId}", 'long_id' => $longId,
            'size' => 10, 'file_count' => count($files), 'expires_at' => now()->addDay(), 'status' => 'pending',
        ]);

        foreach ($files as $path => $content) {
            File::ensureDirectoryExists(dirname("{$base}/{$longId}/{$path}"));
            file_put_contents("{$base}/{$longId}/{$path}", $content);
            $folder = dirname($path) === '.' ? null : dirname($path);
            ShareFile::create([
                'name' => basename($path), 'original_name' => basename($path), 'size' => strlen($content),
                'type' => 'text/plain', 'share_id' => $share->id,
            ])->forceFill(['full_path' => $folder])->save();
        }

        return $share;
    }

    private function finish(Share $share): Share
    {
        (new CreateShareZip($share))->handle();

        return $share->fresh();
    }

    private function storedPath(Share $share, string $name): string
    {
        return storage_path("app/shares/{$share->path}/{$name}");
    }

    // --- The format ---

    #[DataProvider('sizes')]
    public function test_a_file_comes_back_identical(int $size): void
    {
        $this->configureKey();
        $content = $size === 0 ? '' : random_bytes($size);
        $path = $this->file($content);

        $this->encryption()->encryptFile($path);

        $this->assertTrue(StorageEncryption::isEncrypted($path));
        $reader = $this->encryption()->open($path);
        $this->assertSame($size, $reader->size());
        $this->assertTrue($content === $reader->read(0, $size));
    }

    public static function sizes(): array
    {
        return [
            'empty' => [0],
            'one byte' => [1],
            'just under a chunk' => [self::MIB - 1],
            'exactly a chunk' => [self::MIB],
            'just over a chunk' => [self::MIB + 1],
            'several chunks' => [self::MIB * 2 + 512],
        ];
    }

    public function test_any_part_can_be_read_without_the_rest(): void
    {
        $this->configureKey();
        $content = random_bytes(self::MIB * 2 + 100);
        $path = $this->file($content);
        $this->encryption()->encryptFile($path);
        $reader = $this->encryption()->open($path);

        // Across the boundary between two chunks
        $this->assertTrue(substr($content, self::MIB - 10, 20) === $reader->read(self::MIB - 10, 20));
        $this->assertTrue(substr($content, -5) === $reader->read(strlen($content) - 5, 5));
        // A length past the end stops at the end
        $this->assertTrue(substr($content, -5) === $reader->read(strlen($content) - 5, 100));
    }

    public function test_the_plain_content_is_not_on_the_disk(): void
    {
        $this->configureKey();
        $secret = str_repeat('very secret content ', 1000);
        $path = $this->file($secret);

        $this->encryption()->encryptFile($path);

        $this->assertStringNotContainsString('very secret', file_get_contents($path));
    }

    public function test_two_encryptions_of_the_same_file_differ(): void
    {
        $this->configureKey();
        $first = $this->file('same content');
        $second = $this->file('same content');

        $this->encryption()->encryptFile($first);
        $this->encryption()->encryptFile($second);

        $this->assertNotSame(file_get_contents($first), file_get_contents($second));
    }

    public function test_a_modified_byte_is_detected(): void
    {
        $this->configureKey();
        $path = $this->file(random_bytes(1000));
        $this->encryption()->encryptFile($path);
        $bytes = file_get_contents($path);
        $bytes[200] = $bytes[200] ^ "\x01";
        file_put_contents($path, $bytes);

        $this->expectException(RuntimeException::class);
        $this->encryption()->open($path)->read(0, 1000);
    }

    public function test_a_modified_header_is_detected(): void
    {
        $this->configureKey();
        $path = $this->file(random_bytes(1000));
        $this->encryption()->encryptFile($path);
        $bytes = file_get_contents($path);
        // Inside the nonce prefix: the size and the format stay plausible
        $bytes[30] = $bytes[30] ^ "\x01";
        file_put_contents($path, $bytes);

        $this->expectException(RuntimeException::class);
        $this->encryption()->open($path)->read(0, 1000);
    }

    public function test_swapped_chunks_are_detected(): void
    {
        $this->configureKey();
        $path = $this->file(random_bytes(self::MIB * 2));
        $this->encryption()->encryptFile($path);
        $bytes = file_get_contents($path);
        $block = self::MIB + 16;
        $swapped = substr($bytes, 0, 48).substr($bytes, 48 + $block, $block).substr($bytes, 48, $block);
        file_put_contents($path, $swapped);

        $this->expectException(RuntimeException::class);
        $this->encryption()->open($path)->read(0, 10);
    }

    public function test_a_truncated_file_is_detected(): void
    {
        $this->configureKey();
        $path = $this->file(random_bytes(self::MIB * 2));
        $this->encryption()->encryptFile($path);
        $size = filesize($path);
        $handle = fopen($path, 'r+');
        ftruncate($handle, $size - self::MIB - 16);
        fclose($handle);

        // No longer a well-formed encrypted file: it must not pass for one of the original size
        if (StorageEncryption::isEncrypted($path)) {
            $this->fail('A truncated file must not pass as complete');
        }
        $this->expectException(RuntimeException::class);
        $this->encryption()->open($path);
    }

    public function test_another_key_cannot_read_it(): void
    {
        $this->configureKey();
        $path = $this->file('secret');
        $this->encryption()->encryptFile($path);

        $this->configureKey(base64_encode(str_repeat('o', 32)));

        $this->expectException(RuntimeException::class);
        $this->encryption()->open($path);
    }

    /**
     * Otherwise a file that merely starts like an encrypted one could no
     * longer be downloaded.
     */
    public function test_a_plain_file_starting_like_an_encrypted_one_stays_plain(): void
    {
        $path = $this->file(StorageEncryption::MAGIC.str_repeat('x', 500));

        $this->assertFalse(StorageEncryption::isEncrypted($path));
    }

    public function test_a_missing_or_malformed_key_means_no_encryption(): void
    {
        foreach (['', null, 'short', base64_encode('too short'), str_repeat('x', 64)] as $key) {
            config(['app.storage_encryption_key' => $key]);
            $this->assertFalse($this->encryption()->hasKey(), 'Key: '.var_export($key, true));
        }

        $this->expectException(RuntimeException::class);
        $this->encryption()->encryptFile($this->file('secret'));
    }

    public function test_a_generated_key_is_accepted(): void
    {
        $this->assertSame(0, Artisan::call('generate-storage-key'));
        preg_match('/^[A-Za-z0-9+\/=]{44}$/m', Artisan::output(), $match);

        $this->configureKey($match[0]);

        $this->assertTrue($this->encryption()->hasKey());
    }

    // --- Off by default ---

    public function test_nothing_is_encrypted_by_default(): void
    {
        $this->configureKey();
        $this->assertSame('false', Setting::where('key', 'storage_encryption_enabled')->value('value'));

        $share = $this->finish($this->share(['report.txt' => 'plain content']));

        $this->assertSame('plain content', file_get_contents($this->storedPath($share, 'report.txt')));
        $response = $this->post("/api/shares/{$share->long_id}/download");
        $this->assertSame('plain content', file_get_contents($response->baseResponse->getFile()->getPathname()));
    }

    public function test_seeding_on_every_start_keeps_it_on(): void
    {
        $this->enable();

        $this->seed(SettingsSeeder::class);

        $this->assertSame('true', Setting::where('key', 'storage_encryption_enabled')->value('value'));
    }

    // --- New shares ---

    public function test_a_new_single_file_share_is_encrypted_and_downloads_in_clear(): void
    {
        $this->enable();

        $share = $this->finish($this->share(['report.txt' => 'very secret content']));

        $this->assertSame('ready', $share->status);
        $this->assertStringNotContainsString('very secret', file_get_contents($this->storedPath($share, 'report.txt')));

        $response = $this->post("/api/shares/{$share->long_id}/download")->assertOk();

        $this->assertSame('very secret content', $response->streamedContent());
        $this->assertStringContainsString('report.txt', $response->headers->get('Content-Disposition'));
        $this->assertSame('19', $response->headers->get('Content-Length'));
        $this->assertSame(1, $share->fresh()->download_count);
    }

    public function test_the_single_file_route_serves_it_too(): void
    {
        $this->enable();
        $share = $this->finish($this->share(['report.txt' => 'very secret content']));

        $response = $this->get("/api/shares/{$share->long_id}/download/file/report.txt")->assertOk();

        $this->assertSame('very secret content', $response->streamedContent());
    }

    public function test_a_multi_file_share_has_only_an_encrypted_archive(): void
    {
        $this->enable();

        $share = $this->finish($this->share(['a.txt' => 'very secret a', 'folder/b.txt' => 'very secret b']));

        $zipPath = storage_path("app/shares/{$share->path}.zip");
        $this->assertSame('ready', $share->status);
        $this->assertTrue(StorageEncryption::isEncrypted($zipPath));
        $this->assertDirectoryDoesNotExist(storage_path("app/shares/{$share->path}"));

        // The whole archive comes back as a valid zip
        $download = $this->post("/api/shares/{$share->long_id}/download")->assertOk();
        $copy = $this->file($download->streamedContent());
        $zip = new \ZipArchive();
        $this->assertTrue($zip->open($copy));
        $this->assertSame('very secret b', $zip->getFromName('folder/b.txt'));
        $zip->close();
    }

    public function test_one_file_can_be_taken_out_of_an_encrypted_archive(): void
    {
        $this->enable();
        $share = $this->finish($this->share(['a.txt' => 'very secret a', 'folder/b.txt' => str_repeat('very secret b', 1000)]));

        $this->assertSame('very secret a', $this->get("/api/shares/{$share->long_id}/download/file/a.txt")->assertOk()->streamedContent());
        $response = $this->get("/api/shares/{$share->long_id}/download/file/folder/b.txt")->assertOk();
        $this->assertSame(str_repeat('very secret b', 1000), $response->streamedContent());
        $this->assertSame((string) (13 * 1000), $response->headers->get('Content-Length'));
    }

    /**
     * Someone can share a file that is itself in this format, for example a
     * copy taken from a backup: what they get back must be what they sent.
     */
    public function test_a_file_already_in_this_format_is_still_encrypted_and_comes_back_as_sent(): void
    {
        $this->enable();
        $blob = $this->file('some content');
        $this->encryption()->encryptFile($blob);
        $sent = file_get_contents($blob);

        $share = $this->finish($this->share(['backup.bin' => $sent]));

        $this->assertNotSame($sent, file_get_contents($this->storedPath($share, 'backup.bin')));
        $this->assertTrue($sent === $this->post("/api/shares/{$share->long_id}/download")->streamedContent());
    }

    public function test_the_share_is_still_served_when_encryption_cannot_run(): void
    {
        Setting::where('key', 'storage_encryption_enabled')->update(['value' => 'true']);
        app(SettingsService::class)->clearCache();
        $this->configureKey('');

        $share = $this->finish($this->share(['report.txt' => 'content']));

        $this->assertSame('ready', $share->status);
        $this->assertSame('content', file_get_contents($this->storedPath($share, 'report.txt')));
    }

    // --- Byte ranges ---

    private function encryptedShare(string $content): Share
    {
        $this->enable();

        return $this->finish($this->share(['video.bin' => $content]));
    }

    public function test_a_range_is_served_across_chunks(): void
    {
        $content = random_bytes(self::MIB + 100);
        $share = $this->encryptedShare($content);
        $from = self::MIB - 10;
        $to = self::MIB + 9;

        $response = $this->post("/api/shares/{$share->long_id}/download", [], ['Range' => "bytes={$from}-{$to}"])->assertStatus(206);

        $this->assertTrue(substr($content, $from, 20) === $response->streamedContent());
        $this->assertSame('20', $response->headers->get('Content-Length'));
        $this->assertSame("bytes {$from}-{$to}/".strlen($content), $response->headers->get('Content-Range'));
    }

    #[DataProvider('ranges')]
    public function test_range_forms(string $header, int $status, string $expected): void
    {
        $share = $this->encryptedShare('0123456789');

        $response = $this->post("/api/shares/{$share->long_id}/download", [], ['Range' => $header])->assertStatus($status);

        $this->assertSame($expected, $status === 416 ? '' : $response->streamedContent());
    }

    public static function ranges(): array
    {
        return [
            'start and end' => ['bytes=2-4', 206, '234'],
            'open end' => ['bytes=7-', 206, '789'],
            'last bytes' => ['bytes=-3', 206, '789'],
            'end past the file' => ['bytes=8-100', 206, '89'],
            'start past the file' => ['bytes=10-20', 416, ''],
            'reversed' => ['bytes=5-2', 416, ''],
            'several ranges get the whole file' => ['bytes=0-1,3-4', 200, '0123456789'],
            'nonsense gets the whole file' => ['pages=1-2', 200, '0123456789'],
        ];
    }

    // --- When the key changes ---

    public function test_older_plain_shares_stay_readable_once_it_is_on(): void
    {
        $this->configureKey();
        $share = $this->finish($this->share(['old.txt' => 'old content']));

        $this->enable();

        $response = $this->post("/api/shares/{$share->long_id}/download")->assertOk();
        $this->assertSame('old content', file_get_contents($response->baseResponse->getFile()->getPathname()));
    }

    public function test_encrypted_shares_stay_readable_once_it_is_off(): void
    {
        $this->enable();
        $share = $this->finish($this->share(['report.txt' => 'content']));

        Setting::where('key', 'storage_encryption_enabled')->update(['value' => 'false']);
        app(SettingsService::class)->clearCache();

        $this->assertSame('content', $this->post("/api/shares/{$share->long_id}/download")->streamedContent());
    }

    public static function brokenKeys(): array
    {
        return ['missing' => [''], 'another key' => ['b3RoZXItb3RoZXItb3RoZXItb3RoZXItb3RoZXItb3Q=']];
    }

    /**
     * It must fail cleanly: no garbage sent as if it were the file, and the
     * limited download is not spent.
     */
    #[DataProvider('brokenKeys')]
    public function test_without_the_right_key_a_download_fails_and_counts_for_nothing(string $key): void
    {
        $this->enable();
        $share = $this->finish($this->share(['report.txt' => 'very secret content']));
        $this->configureKey($key);

        $response = $this->post("/api/shares/{$share->long_id}/download");

        $this->assertStringNotContainsString('very secret', $response->getContent() ?: '');
        $this->assertStringNotContainsString(StorageEncryption::MAGIC, (string) $response->getContent());
        $this->assertSame(0, $share->fresh()->download_count);
    }

    #[DataProvider('brokenKeys')]
    public function test_without_the_right_key_taking_a_file_out_of_an_archive_fails(string $key): void
    {
        $this->enable();
        $share = $this->finish($this->share(['a.txt' => 'very secret a', 'b.txt' => 'very secret b']));
        $this->configureKey($key);

        $this->get("/api/shares/{$share->long_id}/download/file/a.txt")->assertStatus(500);

        $this->assertSame(0, $share->fresh()->download_count);
    }

    // --- Archives ---

    private function encryptedZip(callable $fill): ZipEntryReader
    {
        $path = $this->dir.'/'.Str::random(8).'.zip';
        $zip = new \ZipArchive();
        $zip->open($path, \ZipArchive::CREATE);
        $fill($zip);
        $zip->close();

        return $this->readerFor($path);
    }

    private function readerFor(string $path): ZipEntryReader
    {
        $this->configureKey();
        $this->encryption()->encryptFile($path);

        return new ZipEntryReader($this->encryption()->open($path));
    }

    private function read(ZipEntryReader $zip, string $name): string
    {
        return implode('', iterator_to_array($zip->stream($zip->find($name)), false));
    }

    public function test_compressed_and_stored_entries_are_read(): void
    {
        $large = random_bytes(600000).str_repeat('compressible', 100000);

        $zip = $this->encryptedZip(function (\ZipArchive $archive) use ($large) {
            $archive->addFromString('deflated.bin', $large);
            $archive->addFromString('stored.bin', 'stored content');
            $archive->setCompressionName('stored.bin', \ZipArchive::CM_STORE);
            $archive->addFromString('dossier é/fichier ñ.txt', 'accents');
            $archive->addFromString('empty.txt', '');
        });

        $this->assertTrue($large === $this->read($zip, 'deflated.bin'));
        $this->assertSame('stored content', $this->read($zip, 'stored.bin'));
        $this->assertSame('accents', $this->read($zip, 'dossier é/fichier ñ.txt'));
        $this->assertSame('', $this->read($zip, 'empty.txt'));
        $this->assertSame(strlen($large), $zip->find('deflated.bin')['size']);
        $this->assertNull($zip->find('absent.txt'));
    }

    /**
     * Archives above 4 GB use this format: too big to create here, so one is
     * written by hand. This is what ZipArchive and zip -fz produce.
     */
    public function test_a_zip64_archive_is_read(): void
    {
        $name = 'large.bin';
        $data = 'content of a large archive';
        $crc = crc32($data);
        $size = strlen($data);

        $local = "PK\x03\x04".pack('vvvvvVVVvv', 45, 0, 0, 0, 0, $crc, $size, $size, strlen($name), 0).$name.$data;
        $extra = pack('vvPPP', 0x0001, 24, $size, $size, 0);
        $central = "PK\x01\x02".pack('vvvvvvVVVvvvvvVV', 45, 45, 0, 0, 0, 0, $crc, 0xFFFFFFFF, 0xFFFFFFFF, strlen($name), strlen($extra), 0, 0, 0, 0, 0xFFFFFFFF).$name.$extra;
        $zip64End = "PK\x06\x06".pack('PvvVVPPPP', 44, 45, 45, 0, 0, 1, 1, strlen($central), strlen($local));
        $locator = "PK\x06\x07".pack('VPV', 0, strlen($local) + strlen($central), 1);
        $end = "PK\x05\x06".pack('vvvvVVv', 0, 0, 0xFFFF, 0xFFFF, 0xFFFFFFFF, 0xFFFFFFFF, 0);

        $path = $this->file($local.$central.$zip64End.$locator.$end);

        $this->assertSame($data, $this->read($this->readerFor($path), $name));
    }

    public function test_something_that_is_not_an_archive_is_refused(): void
    {
        $path = $this->file('not an archive at all');

        $this->expectException(RuntimeException::class);
        $this->readerFor($path)->find('a.txt');
    }

    // --- Administration ---

    private function saveSetting(User $admin, string $value)
    {
        return $this->putJson('/api/settings', ['settings' => [['key' => 'storage_encryption_enabled', 'value' => $value]]], $this->bearer($admin));
    }

    public function test_it_cannot_be_turned_on_without_a_key(): void
    {
        $this->configureKey('');

        $this->saveSetting($this->owner(['admin' => true]), 'true')
            ->assertStatus(422)
            ->assertJson(['code' => 'storage_key_missing']);

        $this->assertSame('false', Setting::where('key', 'storage_encryption_enabled')->value('value'));
    }

    public function test_it_can_be_turned_on_with_a_key_and_off_without_one(): void
    {
        $admin = $this->owner(['admin' => true]);
        $this->configureKey();

        $this->saveSetting($admin, 'true')->assertOk();
        $this->assertSame('true', Setting::where('key', 'storage_encryption_enabled')->value('value'));

        $this->configureKey('');
        $this->saveSetting($admin, 'false')->assertOk();
        $this->assertSame('false', Setting::where('key', 'storage_encryption_enabled')->value('value'));
    }

    public function test_an_admin_is_told_when_the_key_is_gone(): void
    {
        $admin = $this->owner(['admin' => true]);
        $this->enable();

        $this->getJson('/api/settings/storage-encryption', $this->bearer($admin))
            ->assertOk()
            ->assertJson(['data' => ['key_configured' => true, 'misconfigured' => false]]);

        $this->configureKey('');

        $this->getJson('/api/settings/storage-encryption', $this->bearer($admin))
            ->assertOk()
            ->assertJson(['data' => ['key_configured' => false, 'misconfigured' => true]]);
    }

    public function test_the_key_is_never_sent_to_anyone(): void
    {
        $admin = $this->owner(['admin' => true]);
        $this->enable();

        $this->assertStringNotContainsString(
            $this->key,
            $this->getJson('/api/settings/storage-encryption', $this->bearer($admin))->getContent()
        );
    }

    public function test_members_cannot_see_any_of_this(): void
    {
        $member = $this->owner();

        $this->getJson('/api/settings/storage-encryption', $this->bearer($member))->assertForbidden();
        $this->getJson('/api/settings/storage_encryption_enabled', $this->bearer($member))->assertNotFound();
    }

    // --- Existing files ---

    public function test_existing_shares_can_be_encrypted_and_stay_readable(): void
    {
        $this->configureKey();
        $single = $this->finish($this->share(['old.txt' => 'old single']));
        $multi = $this->finish($this->share(['a.txt' => 'old a', 'b.txt' => 'old b']));

        $this->artisan('encrypt-existing-files')->assertSuccessful();

        $this->assertTrue(StorageEncryption::isEncrypted($this->storedPath($single, 'old.txt')));
        $this->assertTrue(StorageEncryption::isEncrypted(storage_path("app/shares/{$multi->path}.zip")));
        $this->assertSame('old single', $this->post("/api/shares/{$single->long_id}/download")->streamedContent());
        $this->assertSame('old a', $this->get("/api/shares/{$multi->long_id}/download/file/a.txt")->streamedContent());
    }

    /**
     * The storage skeleton keeps the folder with an empty placeholder, which
     * is not a share. A hidden file inside a share, however, is one like any other.
     */
    public function test_the_placeholder_of_the_shares_folder_is_left_alone(): void
    {
        $this->configureKey();
        $placeholder = storage_path('app/shares/.placeholder-'.Str::random(8));
        file_put_contents($placeholder, '');
        $share = $this->finish($this->share(['.env' => 'a hidden file']));

        try {
            $this->artisan('encrypt-existing-files')->assertSuccessful();

            $this->assertSame('', file_get_contents($placeholder));
            $this->assertTrue(StorageEncryption::isEncrypted($this->storedPath($share, '.env')));
        } finally {
            @unlink($placeholder);
        }
    }

    public function test_encrypting_twice_changes_nothing(): void
    {
        $this->configureKey();
        $share = $this->finish($this->share(['old.txt' => 'old']));
        $path = $this->storedPath($share, 'old.txt');

        $this->artisan('encrypt-existing-files')->assertSuccessful();
        $once = file_get_contents($path);
        $this->artisan('encrypt-existing-files')->assertSuccessful();

        $this->assertSame($once, file_get_contents($path));
    }

    public function test_files_can_be_decrypted_before_removing_the_key(): void
    {
        $this->enable();
        $share = $this->finish($this->share(['report.txt' => 'content']));

        $this->artisan('decrypt-files')->assertSuccessful();

        $this->assertSame('content', file_get_contents($this->storedPath($share, 'report.txt')));
    }

    public function test_the_commands_refuse_to_run_without_a_key(): void
    {
        $this->configureKey('');
        $share = $this->finish($this->share(['report.txt' => 'content']));

        $this->artisan('encrypt-existing-files')->assertFailed();
        $this->artisan('decrypt-files')->assertFailed();

        $this->assertSame('content', file_get_contents($this->storedPath($share, 'report.txt')));
    }

    public function test_a_failed_decryption_leaves_the_file_intact_and_is_reported(): void
    {
        $this->enable();
        $share = $this->finish($this->share(['report.txt' => 'content']));
        $path = $this->storedPath($share, 'report.txt');
        $before = file_get_contents($path);

        $this->configureKey(base64_encode(str_repeat('o', 32)));

        $this->artisan('decrypt-files')->assertFailed();
        $this->assertSame($before, file_get_contents($path));
    }
}
