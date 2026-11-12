<?php

namespace Tests\Feature;

use App\Models\File;
use App\Models\Share;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TestCase;

// A share behind a password shows nothing of what it holds before the password: not its name,
// its message, its size or the names of its files. Only who sent it and until when it stays.
// The right password opens it, wrong ones count towards the same limit as the downloads.
class ProtectedShareLockTest extends TestCase
{
    use RefreshDatabase;

    protected $seed = true;

    private array $createdDirectories = [];

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
    }

    protected function tearDown(): void
    {
        foreach ($this->createdDirectories as $directory) {
            @unlink($directory.'/budget-2027.xlsx');
            @rmdir($directory);
        }
        parent::tearDown();
    }

    private function protectedShare(array $attributes = []): Share
    {
        $owner = User::factory()->create(['name' => 'Camille Martin', 'admin' => false, 'active' => true, 'is_guest' => false]);
        $path = 'tests-'.Str::random(12);

        $share = Share::create(array_merge([
            'user_id' => $owner->id,
            'name' => 'Draft budget 2027',
            'description' => 'The figures before the council vote',
            'password' => Hash::make('s3cret-pass'),
            'path' => $path,
            'long_id' => 'test-'.Str::lower(Str::random(16)),
            'size' => 12,
            'file_count' => 1,
            'download_limit' => null,
            'download_count' => 0,
            'expires_at' => now()->addDays(7),
            'status' => 'ready',
        ], $attributes));

        File::create(['name' => 'budget-2027.xlsx', 'original_name' => 'budget-2027.xlsx', 'size' => 12, 'type' => 'application/vnd.ms-excel', 'share_id' => $share->id]);

        $directory = storage_path('app/shares/'.$path);
        @mkdir($directory, 0775, true);
        file_put_contents($directory.'/budget-2027.xlsx', 'xlsx-content');
        $this->createdDirectories[] = $directory;

        return $share->fresh();
    }

    public function test_before_the_password_the_share_shows_nothing_of_what_it_holds(): void
    {
        $share = $this->protectedShare();

        $response = $this->getJson("/api/shares/{$share->long_id}")->assertOk();

        $response->assertJsonPath('data.share.password_protected', true)
            ->assertJsonPath('data.share.user.name', 'Camille Martin')
            ->assertJsonPath('data.share.expires_at', $share->expires_at->toJSON())
            ->assertJsonMissingPath('data.share.files')
            ->assertJsonMissingPath('data.share.name')
            ->assertJsonMissingPath('data.share.description')
            ->assertJsonMissingPath('data.share.size')
            ->assertJsonMissingPath('data.share.file_count');
        $this->assertStringNotContainsString('budget-2027', $response->getContent());
        $this->assertStringNotContainsString('Draft budget', $response->getContent());
        $this->assertStringNotContainsString('council vote', $response->getContent());
    }

    public function test_the_right_password_opens_the_share(): void
    {
        $share = $this->protectedShare();

        $this->postJson("/api/shares/{$share->long_id}/unlock", ['password' => 's3cret-pass'])
            ->assertOk()
            ->assertJsonPath('data.share.name', 'Draft budget 2027')
            ->assertJsonPath('data.share.description', 'The figures before the council vote')
            ->assertJsonPath('data.share.files.0.name', 'budget-2027.xlsx')
            ->assertJsonPath('data.share.password_protected', true);

        // Opening is not downloading
        $this->assertSame(0, $share->fresh()->download_count);
    }

    public function test_a_wrong_or_missing_password_opens_nothing(): void
    {
        $share = $this->protectedShare();

        foreach ([['password' => 'wrong'], [], ['password' => ['s3cret-pass']]] as $body) {
            $response = $this->postJson("/api/shares/{$share->long_id}/unlock", $body)->assertUnauthorized();
            $this->assertStringNotContainsString('budget-2027', $response->getContent());
        }
    }

    public function test_guessing_the_password_is_limited_like_the_download(): void
    {
        $share = $this->protectedShare();

        for ($attempt = 0; $attempt < 10; $attempt++) {
            $this->postJson("/api/shares/{$share->long_id}/unlock", ['password' => "guess-{$attempt}"])->assertUnauthorized();
        }

        // Even the right password waits once the limit is reached
        $this->postJson("/api/shares/{$share->long_id}/unlock", ['password' => 's3cret-pass'])->assertStatus(429);
        $this->post("/api/shares/{$share->long_id}/download", ['password' => 's3cret-pass'])
            ->assertRedirect("/shares/{$share->long_id}?error=too_many_attempts");
    }

    public function test_an_expired_share_does_not_open(): void
    {
        $share = $this->protectedShare(['expires_at' => now()->subMinute()]);

        $this->postJson("/api/shares/{$share->long_id}/unlock", ['password' => 's3cret-pass'])->assertStatus(410);
        $this->postJson('/api/shares/does-not-exist/unlock', ['password' => 's3cret-pass'])->assertNotFound();
    }

    public function test_one_file_is_downloaded_with_the_password_in_the_body_not_the_address(): void
    {
        $share = $this->protectedShare();

        $this->post("/api/shares/{$share->long_id}/download/file/budget-2027.xlsx", ['password' => 'wrong'])->assertUnauthorized();
        $this->post("/api/shares/{$share->long_id}/download/file/budget-2027.xlsx", ['password' => 's3cret-pass'])
            ->assertOk()
            ->assertDownload('budget-2027.xlsx');
    }
}
