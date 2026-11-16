<?php

namespace Tests\Feature;

use App\Jobs\maintainDb;
use App\Models\ReverseShareInvite;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File as Filesystem;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

// The daily maintenance clears what failed or abandoned uploads leave behind. A file request
// may stay open a month: the guest account behind its link must last as long, and go once the
// request is over. The files tusd kept for an upload nobody finished go too.
class DailyMaintenanceTest extends TestCase
{
    use RefreshDatabase;

    protected $seed = true;

    private array $files = [];

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        Filesystem::ensureDirectoryExists(storage_path('app/uploads'));
    }

    protected function tearDown(): void
    {
        foreach ($this->files as $file) {
            Filesystem::delete($file);
        }
        parent::tearDown();
    }

    private function request(array $attributes): ReverseShareInvite
    {
        $member = User::factory()->create(['admin' => false, 'is_guest' => false]);
        $guest = User::factory()->create(['is_guest' => true]);
        $guest->forceFill(['created_at' => now()->subDays(10)])->save();

        return ReverseShareInvite::create(array_merge([
            'user_id' => $member->id,
            'guest_user_id' => $guest->id,
            'recipient_name' => 'Dossier Martin',
            'recipient_email' => '',
        ], $attributes));
    }

    private function upload(string $name, int $daysOld): string
    {
        $path = storage_path("app/uploads/{$name}");
        file_put_contents($path, 'partial');
        touch($path, now()->subDays($daysOld)->getTimestamp());
        $this->files[] = $path;

        return $path;
    }

    public function test_the_guest_of_a_request_still_open_stays_past_a_week(): void
    {
        $request = $this->request(['expires_at' => now()->addDays(20)]);

        (new maintainDb())->handle();

        $this->assertNotNull(User::find($request->guest_user_id));
    }

    public function test_the_guest_of_an_expired_request_goes(): void
    {
        $request = $this->request(['expires_at' => now()->subDay()]);
        $guestId = $request->guest_user_id;

        (new maintainDb())->handle();

        $this->assertNull(User::find($guestId));
        $this->assertNotNull(ReverseShareInvite::find($request->id), 'the request stays in the list of its member');
    }

    public function test_an_upload_nobody_finished_leaves_the_disk_after_a_week(): void
    {
        $old = $this->upload('abandoned-'.uniqid(), 8);
        $info = $this->upload(basename($old).'.info', 8);
        $recent = $this->upload('in-progress-'.uniqid(), 2);

        (new maintainDb())->handle();

        $this->assertFileDoesNotExist($old);
        $this->assertFileDoesNotExist($info);
        $this->assertFileExists($recent);
    }
}
