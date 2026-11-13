<?php

namespace Tests\Feature;

use App\Jobs\sendEmail;
use App\Mail\shareDeletedWarningMail;
use App\Models\ReverseShareInvite;
use App\Models\Share;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TestCase;

// Once a share has expired long enough, its files are cleaned away and it is marked deleted.
// A folder sent with its subfolders, or files dropped by the guest of a file request (a share
// with no owner), must be cleaned like any other: otherwise they stay on the disk for good.
class ShareCleaningTest extends TestCase
{
    use RefreshDatabase;

    protected $seed = true;

    private array $paths = [];

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
    }

    protected function tearDown(): void
    {
        foreach ($this->paths as $path) {
            \Illuminate\Support\Facades\File::deleteDirectory($path);
        }
        parent::tearDown();
    }

    private function shareOnDisk(array $attributes, array $files): Share
    {
        $path = 'tests-'.Str::random(12);
        $share = (new Share())->forceFill(array_merge([
            'name' => 'Dossier',
            'path' => $path,
            'long_id' => 'test-'.Str::lower(Str::random(16)),
            'size' => 10,
            'file_count' => count($files),
            'download_count' => 0,
            'expires_at' => now()->subDays(40),
            'status' => 'ready',
        ], $attributes));
        $share->save();

        $root = storage_path('app/shares/'.$path);
        $this->paths[] = $root;
        foreach ($files as $file) {
            @mkdir(dirname("$root/$file"), 0775, true);
            file_put_contents("$root/$file", 'content');
        }

        return $share;
    }

    public function test_a_folder_with_subfolders_is_cleaned_away(): void
    {
        $owner = User::factory()->create();
        $share = $this->shareOnDisk(['user_id' => $owner->id], ['plans/rdc/plan.pdf', 'plans/etage.pdf', 'note.txt']);

        $this->assertTrue($share->cleanFiles());

        $this->assertDirectoryDoesNotExist(storage_path('app/shares/'.$share->path));
        $this->assertSame('deleted', $share->fresh()->status);
    }

    public function test_a_share_without_a_path_never_cleans_the_others(): void
    {
        $owner = User::factory()->create();
        $other = $this->shareOnDisk(['user_id' => $owner->id], ['rapport.pdf']);
        $broken = $this->shareOnDisk(['user_id' => $owner->id], []);
        $broken->forceFill(['path' => ''])->save();

        $this->assertFalse($broken->cleanFiles());

        $this->assertFileExists(storage_path('app/shares/'.$other->path.'/rapport.pdf'));
    }

    public function test_files_dropped_by_a_guest_are_cleaned_and_the_member_who_asked_is_told(): void
    {
        $member = User::factory()->create(['email' => 'camille@entreprise.example']);
        $invite = ReverseShareInvite::create([
            'user_id' => $member->id,
            'recipient_name' => 'Marie Durand',
            'recipient_email' => 'marie@fournisseur.example',
            'expires_at' => now()->subDays(45),
        ]);
        $share = $this->shareOnDisk(['user_id' => null, 'invite_id' => $invite->id, 'public' => false], ['contrat.pdf']);

        $this->assertTrue($share->cleanFiles());

        $this->assertSame('deleted', $share->fresh()->status);
        Queue::assertPushed(sendEmail::class, fn (sendEmail $job) => $job->to === 'camille@entreprise.example'
            && $job->mailableClass === shareDeletedWarningMail::class);
    }
}
