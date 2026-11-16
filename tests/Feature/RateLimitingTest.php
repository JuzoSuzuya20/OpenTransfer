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

/**
 * Sans limite, un mot de passe, un mot de passe de partage ou un code de
 * vérification à 6 chiffres se devinent par force brute.
 */
class RateLimitingTest extends TestCase
{
    use RefreshDatabase;

    protected $seed = true;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
    }

    private function user(array $attributes = []): User
    {
        return User::factory()->create(array_merge(['admin' => false, 'active' => true, 'is_guest' => false], $attributes));
    }

    public function test_login_is_locked_after_four_failed_attempts(): void
    {
        $user = $this->user();

        for ($i = 0; $i < 4; $i++) {
            $this->postJson('/api/auth/login', ['email' => $user->email, 'password' => 'wrong'])->assertUnauthorized();
        }

        // Même le bon mot de passe est refusé tant que le verrou est actif
        $this->postJson('/api/auth/login', ['email' => $user->email, 'password' => 'password'])
            ->assertStatus(429)
            ->assertJsonMissingPath('data.access_token');
    }

    public function test_a_login_lock_does_not_affect_other_accounts(): void
    {
        $victim = $this->user();
        $other = $this->user();

        for ($i = 0; $i < 6; $i++) {
            $this->postJson('/api/auth/login', ['email' => $victim->email, 'password' => 'wrong']);
        }

        $this->postJson('/api/auth/login', ['email' => $other->email, 'password' => 'password'])->assertOk();
    }

    public function test_a_successful_login_resets_the_counter(): void
    {
        $user = $this->user();

        for ($round = 0; $round < 2; $round++) {
            // Three misses, one short of the lock: the right password then starts the count again
            for ($i = 0; $i < 3; $i++) {
                $this->postJson('/api/auth/login', ['email' => $user->email, 'password' => 'wrong']);
            }
            $this->postJson('/api/auth/login', ['email' => $user->email, 'password' => 'password'])->assertOk();
            $this->app['auth']->forgetGuards();
        }
    }

    public function test_email_verification_codes_cannot_be_brute_forced(): void
    {
        $user = $this->user([
            'active' => false,
            'email_verification_code' => '424242',
            'email_verification_code_expires_at' => now()->addMinutes(15),
        ]);

        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/auth/verify-email', ['email' => $user->email, 'code' => sprintf('%06d', $i)])->assertStatus(400);
        }

        $this->postJson('/api/auth/verify-email', ['email' => $user->email, 'code' => '424242'])->assertStatus(429);
        $this->assertFalse((bool) $user->fresh()->active);
    }

    public function test_share_passwords_cannot_be_brute_forced(): void
    {
        $owner = $this->user();
        $path = 'tests-'.Str::random(12);
        $share = Share::create([
            'user_id' => $owner->id, 'name' => 'Contract', 'path' => $path,
            'long_id' => 'test-'.Str::lower(Str::random(16)), 'size' => 3, 'file_count' => 1,
            'expires_at' => now()->addDay(), 'status' => 'ready', 'password' => Hash::make('right-password'),
        ]);
        File::create(['name' => 'contract.pdf', 'original_name' => 'contract.pdf', 'size' => 3, 'type' => 'application/pdf', 'share_id' => $share->id]);
        @mkdir(storage_path("app/shares/{$path}"), 0775, true);
        file_put_contents(storage_path("app/shares/{$path}/contract.pdf"), 'pdf');

        try {
            foreach ([3, 2, 1] as $left) {
                $this->post("/api/shares/{$share->long_id}/download", ['password' => 'guess'])
                    ->assertRedirect("/shares/{$share->long_id}?error=invalid_password&attempts_left={$left}");
            }
            $last = $this->post("/api/shares/{$share->long_id}/download", ['password' => 'guess'])->headers->get('Location');
            $this->assertMatchesRegularExpression('/\?error=invalid_password&attempts_left=0&retry_after=\d+$/', $last);

            $wait = $this->post("/api/shares/{$share->long_id}/download", ['password' => 'right-password'])->headers->get('Location');
            $this->assertMatchesRegularExpression('/\?error=too_many_attempts&retry_after=\d+$/', $wait);
            $this->getJson("/api/shares/{$share->long_id}/download/file/contract.pdf?password=right-password")
                ->assertStatus(429);
            $this->assertSame(0, $share->fresh()->download_count);
        } finally {
            @unlink(storage_path("app/shares/{$path}/contract.pdf"));
            @rmdir(storage_path("app/shares/{$path}"));
        }
    }
}
