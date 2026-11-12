<?php

namespace Tests\Feature;

use App\Models\Setting;
use App\Models\User;
use App\Services\SettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

// tusd checks the size an upload declares when it starts, and refuses the bytes beyond it. The
// tus protocol also lets a client declare its size later (Upload-Defer-Length): the size seen
// at the start is then 0, and an upload of any size went through, enough to fill the disk.
class UploadSizeTest extends TestCase
{
    use RefreshDatabase;

    protected $seed = true;

    private function preCreate(User $user, int $size, bool $deferred = false)
    {
        return $this->withServerVariables(['REMOTE_ADDR' => '127.0.0.1'])->postJson('/api/tusd-hooks', [
            'Type' => 'pre-create',
            'Event' => [
                'Upload' => ['Size' => $size, 'SizeIsDeferred' => $deferred, 'MetaData' => ['filename' => 'archive.bin']],
                'HTTPRequest' => ['Header' => ['Authorization' => ['Bearer '.auth('api')->tokenById($user->id)]]],
            ],
        ]);
    }

    private function member(): User
    {
        return User::factory()->create(['admin' => false, 'active' => true, 'must_change_password' => false, 'is_guest' => false]);
    }

    public function test_an_upload_that_hides_its_size_is_refused(): void
    {
        $response = $this->preCreate($this->member(), 0, true);

        $this->assertFalse($response->json('ok') ?? true);
        $this->assertGreaterThanOrEqual(400, $response->status());
    }

    public function test_an_upload_over_the_max_share_size_is_refused(): void
    {
        Setting::where('key', 'max_share_size')->update(['value' => '1']);
        Setting::where('key', 'max_share_size_unit')->update(['value' => 'MB']);
        app(SettingsService::class)->clearCache();

        $this->assertSame(413, $this->preCreate($this->member(), 2 * 1024 * 1024)->status());
    }

    public function test_an_upload_that_says_its_size_goes_through(): void
    {
        $this->preCreate($this->member(), 1024)->assertOk();
    }
}
