<?php

namespace Tests\Feature;

use App\Models\File;
use App\Models\ReverseShareInvite;
use App\Models\Setting;
use App\Models\Share;
use App\Models\UploadSession;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File as Filesystem;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

// The member who asks for files sets the rules of the request: how long the files they receive
// stay online, and a password the guest must give before dropping anything. The guest only drops
// files: a password or a lifetime they send along is not theirs to set.
class FileRequestRulesTest extends TestCase
{
    use RefreshDatabase;

    protected $seed = true;

    private array $guestFolders = [];

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        Setting::where('key', 'max_expiry_time')->update(['value' => '30']);
        Setting::where('key', 'default_expiry_time')->update(['value' => '7']);
        Filesystem::ensureDirectoryExists(storage_path('app/uploads'));
    }

    protected function tearDown(): void
    {
        foreach ($this->guestFolders as $folder) {
            Filesystem::deleteDirectory(storage_path("app/shares/{$folder}"));
        }
        parent::tearDown();
    }

    private function member(): User
    {
        return User::factory()->create(['name' => 'Camille Martin', 'admin' => false, 'active' => true, 'must_change_password' => false, 'is_guest' => false]);
    }

    private function bearer(User $user): array
    {
        return ['Authorization' => 'Bearer '.auth('api')->tokenById($user->id)];
    }

    // The token of the link the member passes on
    private function ask(array $rules = []): string
    {
        $link = $this->postJson('/api/reverse-shares/invite', array_merge([
            'delivery' => 'link',
            'recipient_name' => 'Dossier Martin',
            'message' => 'Les pièces signées',
            'expires_in_days' => 3,
        ], $rules), $this->bearer($this->member()))->assertOk()->json('data.link');
        $this->app['auth']->forgetGuards();
        $this->app->forgetInstance('tymon.jwt');
        parse_str((string) parse_url($link, PHP_URL_QUERY), $query);

        return $query['invite_token'];
    }

    private function open(string $token, ?string $password = null)
    {
        $this->app['auth']->forgetGuards();
        $this->app->forgetInstance('tymon.jwt');
        $fields = ['token' => $token];
        if ($password !== null) {
            $fields['password'] = $password;
        }

        return $this->postJson('/api/reverse-shares/accept', $fields);
    }

    // The guest drops one file with the session the link gave, trying to set rules of their own
    private function drop(string $session)
    {
        $this->app['auth']->forgetGuards();
        $this->app->forgetInstance('tymon.jwt');
        $guest = auth('api')->setToken($session)->user();
        $this->app['auth']->forgetGuards();
        $this->app->forgetInstance('tymon.jwt');
        $this->guestFolders[] = $guest->id;

        $uploadId = 'rules-'.uniqid();
        file_put_contents(storage_path("app/uploads/{$uploadId}"), 'signed');
        $file = File::create(['name' => 'devis.pdf', 'original_name' => 'devis.pdf', 'size' => 6, 'type' => 'application/pdf', 'temp_path' => "uploads/{$uploadId}"]);
        UploadSession::create(['upload_id' => $uploadId, 'user_id' => $guest->id, 'filename' => 'devis.pdf', 'filesize' => 6, 'filetype' => 'application/pdf', 'total_chunks' => 1, 'chunks_received' => 1, 'status' => 'complete', 'file_id' => $file->id]);

        return $this->postJson('/api/uploads/create-share-from-uploads', [
            'upload_id' => $uploadId,
            'name' => 'Pièces',
            'uploadIds' => [$uploadId],
            'filePaths' => [$uploadId => 'devis.pdf'],
            'expiry_date' => now()->addDay()->toDateString(),
            'password' => 'set-by-the-guest',
            'password_confirm' => 'set-by-the-guest',
        ], ['Authorization' => "Bearer {$session}"]);
    }

    public function test_the_files_received_stay_as_long_as_the_member_chose(): void
    {
        $token = $this->ask(['keep_files_days' => 14]);
        $session = $this->open($token)->assertOk()->json('data.access_token');

        $this->drop($session)->assertOk();

        $share = Share::sole();
        $this->assertTrue($share->expires_at->between(now()->addDays(14)->subMinute(), now()->addDays(14)->addMinute()));
    }

    public function test_the_guest_cannot_put_a_password_on_the_files(): void
    {
        $session = $this->open($this->ask())->assertOk()->json('data.access_token');

        $this->drop($session)->assertOk();

        $this->assertNull(Share::sole()->password);
    }

    public function test_a_request_without_a_choice_keeps_the_files_for_the_default_time(): void
    {
        $session = $this->open($this->ask())->assertOk()->json('data.access_token');

        $this->drop($session)->assertOk();

        $this->assertTrue(Share::sole()->expires_at->between(now()->addDays(7)->subMinute(), now()->addDays(7)->addMinute()));
    }

    public function test_the_files_cannot_stay_longer_than_the_administration_allows(): void
    {
        $this->postJson('/api/reverse-shares/invite', [
            'delivery' => 'link',
            'recipient_name' => 'Dossier Martin',
            'keep_files_days' => 31,
        ], $this->bearer($this->member()))->assertUnprocessable();

        $this->assertSame(0, ReverseShareInvite::count());
    }

    public function test_the_password_of_a_request_is_kept_hashed(): void
    {
        $this->ask(['password' => 'lot-3-signé']);

        $stored = ReverseShareInvite::sole()->password;
        $this->assertNotSame('lot-3-signé', $stored);
        $this->assertTrue(Hash::check('lot-3-signé', $stored));
    }

    public function test_a_protected_request_does_not_open_without_its_password(): void
    {
        $token = $this->ask(['password' => 'lot-3-signé']);

        $this->open($token)->assertUnauthorized()
            ->assertJsonPath('message', 'Password required')
            ->assertJsonPath('data.request.from', 'Camille Martin')
            ->assertJsonMissingPath('data.access_token')
            ->assertJsonMissingPath('data.request.message');
        $this->open($token, 'wrong')->assertUnauthorized()->assertJsonPath('message', 'Invalid password');

        // Neither attempt used up the link
        $this->assertNull(ReverseShareInvite::sole()->used_at);
        $this->open($token, 'lot-3-signé')->assertOk()->assertJsonStructure(['data' => ['access_token']]);
    }

    public function test_the_link_alone_no_longer_opens_a_protected_request_by_the_old_way(): void
    {
        $token = $this->ask(['password' => 'lot-3-signé']);

        $this->app['auth']->forgetGuards();
        $this->app->forgetInstance('tymon.jwt');
        $this->getJson('/api/reverse-shares/accept?token='.urlencode($token))->assertUnauthorized();
        $this->assertNull(ReverseShareInvite::sole()->used_at);
    }

    public function test_guessing_the_password_of_a_request_is_slowed_down(): void
    {
        $token = $this->ask(['password' => 'lot-3-signé']);

        for ($i = 0; $i < 4; $i++) {
            $this->open($token, "guess-{$i}")->assertUnauthorized();
        }

        $this->open($token, 'lot-3-signé')->assertStatus(429);
        $this->assertNull(ReverseShareInvite::sole()->used_at);
    }

    public function test_a_request_without_a_password_still_opens_with_the_link(): void
    {
        $token = $this->ask();

        $this->app['auth']->forgetGuards();
        $this->app->forgetInstance('tymon.jwt');
        $this->getJson('/api/reverse-shares/accept?token='.urlencode($token))->assertOk();
    }

    public function test_the_guest_page_knows_who_asks_and_how_much_time_is_left(): void
    {
        $session = $this->open($this->ask(['keep_files_days' => 14]))->json('data.access_token');
        $this->app['auth']->forgetGuards();
        $this->app->forgetInstance('tymon.jwt');

        $request = $this->getJson('/api/users/me', ['Authorization' => "Bearer {$session}"])->assertOk()->json('data.user.request');

        $this->assertSame('Camille Martin', $request['from']);
        $this->assertArrayHasKey('avatar_seed', $request);
        $this->assertArrayNotHasKey('password', $request);
    }
}
