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

// A password is tried four times, then waits: the sign-in, the password of a share, the password
// of a file request. Each refusal tells the page how many tries remain (it says so from the
// second one on), and the last one how long to wait.
class PasswordAttemptsTest extends TestCase
{
    use RefreshDatabase;

    protected $seed = true;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
    }

    private function fresh(): void
    {
        $this->app['auth']->forgetGuards();
        $this->app->forgetInstance('tymon.jwt');
    }

    private function member(array $attributes = []): User
    {
        return User::factory()->create(array_merge(['admin' => false, 'active' => true, 'must_change_password' => false, 'is_guest' => false], $attributes));
    }

    // Four refusals counting down to zero, then the wait, whatever the password
    private function assertFourTries(callable $try, callable $right, string $lockCode = 'too_many_attempts'): void
    {
        foreach ([3, 2, 1] as $left) {
            $try()->assertUnauthorized()->assertJsonPath('data.attempts_left', $left)->assertJsonMissingPath('data.retry_after');
        }
        $last = $try()->assertUnauthorized()->assertJsonPath('data.attempts_left', 0);
        $this->assertGreaterThan(0, $last->json('data.retry_after'));

        $right()->assertStatus(429)->assertJsonPath('code', $lockCode);
        $this->assertGreaterThan(0, $right()->json('data.retry_after'));
    }

    public function test_the_sign_in_gives_four_tries(): void
    {
        $user = $this->member();
        $sign = fn (string $password) => fn () => $this->postJson('/api/auth/login', ['email' => $user->email, 'password' => $password]);

        $this->assertFourTries($sign('wrong'), $sign('password'));
    }

    public function test_an_address_that_does_not_exist_counts_the_same(): void
    {
        $this->postJson('/api/auth/login', ['email' => 'personne@example.test', 'password' => 'wrong'])
            ->assertUnauthorized()
            ->assertJsonPath('message', 'Invalid email or password')
            ->assertJsonPath('data.attempts_left', 3);
    }

    public function test_the_password_of_a_share_gives_four_tries(): void
    {
        $owner = $this->member();
        $share = Share::create([
            'user_id' => $owner->id, 'name' => 'Budget', 'password' => Hash::make('s3cret-pass'),
            'path' => 'tests-'.Str::random(12), 'long_id' => 'test-'.Str::lower(Str::random(16)),
            'size' => 1, 'file_count' => 1, 'download_count' => 0, 'expires_at' => now()->addDays(7), 'status' => 'ready',
        ]);
        File::create(['name' => 'a.txt', 'original_name' => 'a.txt', 'size' => 1, 'type' => 'text/plain', 'share_id' => $share->id]);
        $unlock = fn (string $password) => fn () => $this->postJson("/api/shares/{$share->long_id}/unlock", ['password' => $password]);

        $this->assertFourTries($unlock('wrong'), $unlock('s3cret-pass'));
    }

    // The page tells a refused password from a session that ran out by these codes: a visitor saw
    // no message at all, and a member had each wrong password sent, and counted, twice
    public function test_a_refused_share_password_says_so_by_its_code(): void
    {
        $owner = $this->member();
        $share = Share::create([
            'user_id' => $owner->id, 'name' => 'Budget', 'password' => Hash::make('s3cret-pass'),
            'path' => 'tests-'.Str::random(12), 'long_id' => 'test-'.Str::lower(Str::random(16)),
            'size' => 1, 'file_count' => 1, 'download_count' => 0, 'expires_at' => now()->addDays(7), 'status' => 'ready',
        ]);

        $this->postJson("/api/shares/{$share->long_id}/unlock", [])->assertUnauthorized()->assertJsonPath('code', 'password_required');
        $this->postJson("/api/shares/{$share->long_id}/unlock", ['password' => 'wrong'])->assertUnauthorized()->assertJsonPath('code', 'invalid_password');
    }

    public function test_the_password_of_a_file_request_gives_four_tries(): void
    {
        $member = $this->member();
        $this->fresh();
        $link = $this->postJson('/api/reverse-shares/invite', ['delivery' => 'link', 'recipient_name' => 'Dossier Martin', 'password' => 'lot-3'], [
            'Authorization' => 'Bearer '.auth('api')->tokenById($member->id),
        ])->assertOk()->json('data.link');
        parse_str((string) parse_url($link, PHP_URL_QUERY), $query);
        $open = fn (string $password) => function () use ($query, $password) {
            $this->fresh();

            return $this->postJson('/api/reverse-shares/accept', ['token' => $query['invite_token'], 'password' => $password]);
        };

        $this->assertFourTries($open('wrong'), $open('lot-3'), 'request_password_attempts');
    }
}
