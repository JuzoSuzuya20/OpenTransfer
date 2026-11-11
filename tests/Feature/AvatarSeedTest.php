<?php

namespace Tests\Feature;

use App\Models\File;
use App\Models\Share;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Every account has a drawn avatar, generated in the browser from a seed the server gives: the same
 * seed, the same face, wherever the account appears. The seed reveals neither the identifier nor the
 * address of the account, and nobody can choose it.
 */
class AvatarSeedTest extends TestCase
{
    use RefreshDatabase;

    protected $seed = true;

    private function user(array $attributes = []): User
    {
        return User::factory()->create(array_merge(['admin' => false, 'active' => true], $attributes));
    }

    private function seedOf(User $user): ?string
    {
        return $this->actingAs($user, 'api')->getJson('/api/users/me')->assertOk()->json('data.user.avatar_seed');
    }

    public function test_an_account_gets_a_seed_for_its_avatar(): void
    {
        $user = $this->user();

        $seed = $this->seedOf($user);

        $this->assertMatchesRegularExpression('/^[0-9a-f]{20}$/', $seed);
        $this->assertSame($seed, $this->seedOf($user->fresh()));
    }

    public function test_two_accounts_get_two_seeds(): void
    {
        $this->assertNotSame($this->seedOf($this->user()), $this->seedOf($this->user()));
    }

    public function test_the_seed_does_not_reveal_the_account(): void
    {
        $user = $this->user(['email' => 'camille@entreprise.fr']);
        $seed = $this->seedOf($user);

        $this->assertNotNull($seed);
        $this->assertNotSame(substr(hash('sha256', (string) $user->id), 0, 20), $seed);
        $this->assertNotSame(substr(hash('sha256', $user->email), 0, 20), $seed);
        $this->assertNotSame(substr(md5($user->email), 0, 20), $seed);
    }

    public function test_nobody_chooses_the_seed(): void
    {
        $user = $this->user();
        $seed = $this->seedOf($user);

        $this->actingAs($user->fresh(), 'api')->putJson('/api/users/me', ['avatar_seed' => 'aaaaaaaaaaaaaaaaaaaa', 'name' => 'Camille'])->assertOk();

        $this->assertSame($seed, $this->seedOf($user->fresh()));
    }

    public function test_the_recipient_of_a_share_sees_the_avatar_of_the_sender(): void
    {
        $owner = $this->user();
        $share = Share::create([
            'user_id' => $owner->id, 'name' => 'Dossier', 'path' => "{$owner->id}/avatar", 'long_id' => 'avatar-'.Str::lower(Str::random(8)),
            'size' => 1, 'file_count' => 1, 'expires_at' => now()->addDay(), 'status' => 'ready',
        ]);
        File::create(['name' => 'a.pdf', 'original_name' => 'a.pdf', 'size' => 1, 'type' => 'application/pdf', 'share_id' => $share->id]);

        $seed = $this->seedOf($owner);
        $this->assertNotNull($seed);

        $this->getJson("/api/shares/{$share->long_id}")
            ->assertOk()
            ->assertJsonPath('data.share.user.avatar_seed', $seed);
    }

    public function test_an_admin_sees_the_avatar_of_the_owner_in_the_list_of_all_shares(): void
    {
        $owner = $this->user();
        $admin = $this->user(['admin' => true]);
        $share = Share::create([
            'user_id' => $owner->id, 'name' => 'Dossier', 'path' => "{$owner->id}/admin-list", 'long_id' => 'admin-'.Str::lower(Str::random(8)),
            'size' => 1, 'file_count' => 1, 'expires_at' => now()->addDay(), 'status' => 'ready',
        ]);
        File::create(['name' => 'a.pdf', 'original_name' => 'a.pdf', 'size' => 1, 'type' => 'application/pdf', 'share_id' => $share->id]);

        $this->actingAs($admin, 'api')->getJson('/api/shares/all')
            ->assertOk()
            ->assertJsonPath('data.shares.0.user_avatar_seed', $owner->fresh()->avatar_seed);
    }
}
