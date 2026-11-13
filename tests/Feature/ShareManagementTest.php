<?php

namespace Tests\Feature;

use App\Models\Setting;
use App\Models\Share;
use App\Models\User;
use App\Services\SettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

// What the owner of a share may change after sending it, within what the admin allows: the
// longest time a share stays online holds for extensions as for the first send, and only the
// owner (or an admin) touches a share.
class ShareManagementTest extends TestCase
{
    use RefreshDatabase;

    protected $seed = true;

    private function member(): User
    {
        return User::factory()->create(['admin' => false, 'active' => true, 'must_change_password' => false, 'is_guest' => false]);
    }

    private function bearer(User $user): array
    {
        return ['Authorization' => 'Bearer '.auth('api')->tokenById($user->id)];
    }

    private function share(User $owner, array $attributes = []): Share
    {
        return Share::create(array_merge([
            'user_id' => $owner->id,
            'name' => 'Rapport annuel',
            'path' => 'tests-'.Str::random(12),
            'long_id' => 'test-'.Str::lower(Str::random(16)),
            'size' => 10,
            'file_count' => 1,
            'download_limit' => null,
            'download_count' => 0,
            'expires_at' => now()->addDays(5),
            'status' => 'ready',
        ], $attributes));
    }

    private function maxDays(string $days): void
    {
        Setting::where('key', 'max_expiry_time')->update(['value' => $days]);
        app(SettingsService::class)->clearCache();
    }

    public function test_extending_never_keeps_a_share_online_longer_than_the_admin_allows(): void
    {
        $this->maxDays('10');
        $owner = $this->member();
        $share = $this->share($owner, ['expires_at' => now()->addDays(2)]);
        $share->forceFill(['created_at' => now()->subDays(6)])->save();

        $this->postJson("/api/shares/{$share->id}/extend", [], $this->bearer($owner))->assertOk();

        // Ten days from the day it was sent, not seven more from now
        $this->assertTrue($share->fresh()->expires_at->lessThanOrEqualTo($share->created_at->copy()->addDays(10)->addSecond()));
        $this->assertTrue($share->fresh()->expires_at->greaterThan(now()->addDays(3)));
    }

    public function test_a_share_already_at_the_longest_time_allowed_is_not_extended(): void
    {
        $this->maxDays('10');
        $owner = $this->member();
        $share = $this->share($owner, ['expires_at' => now()->addDays(2)]);
        $share->forceFill(['created_at' => now()->subDays(8)])->save();
        $before = $share->fresh()->expires_at;

        // Extending as many times as one likes cannot get past the limit
        for ($i = 0; $i < 3; $i++) {
            $this->postJson("/api/shares/{$share->id}/extend", [], $this->bearer($owner));
        }
        $this->postJson("/api/shares/{$share->id}/extend", [], $this->bearer($owner))
            ->assertUnprocessable()
            ->assertJsonPath('code', 'max_expiry_reached');

        $this->assertTrue($share->fresh()->expires_at->lessThanOrEqualTo($share->created_at->copy()->addDays(10)->addSecond()));
        $this->assertTrue($share->fresh()->expires_at->greaterThanOrEqualTo($before));
    }

    public function test_without_a_longest_time_a_share_is_extended_by_a_week(): void
    {
        $this->maxDays('');
        $owner = $this->member();
        $share = $this->share($owner);

        $this->postJson("/api/shares/{$share->id}/extend", [], $this->bearer($owner))->assertOk();

        $this->assertEqualsWithDelta(now()->addDays(7)->timestamp, $share->fresh()->expires_at->timestamp, 5);
    }

    // Its files were cleaned away: extended, it would show as active with nothing to download
    public function test_a_share_whose_files_were_deleted_is_not_extended(): void
    {
        $owner = $this->member();
        $share = $this->share($owner, ['expires_at' => now()->subDays(40), 'status' => 'deleted']);

        $this->postJson("/api/shares/{$share->id}/extend", [], $this->bearer($owner))
            ->assertUnprocessable()
            ->assertJsonPath('code', 'share_deleted');

        $this->assertTrue($share->fresh()->expires_at->isPast());
    }

    public function test_someone_else_cannot_change_a_share(): void
    {
        $share = $this->share($this->member());
        $other = $this->member();

        $this->postJson("/api/shares/{$share->id}/extend", [], $this->bearer($other))->assertUnauthorized();
        $this->postJson("/api/shares/{$share->id}/expire", [], $this->bearer($other))->assertUnauthorized();
        $this->postJson("/api/shares/{$share->id}/set-download-limit", ['amount' => 3], $this->bearer($other))->assertUnauthorized();

        $this->assertNull($share->fresh()->download_limit);
    }

    public function test_a_download_limit_is_a_whole_number_or_none(): void
    {
        $owner = $this->member();
        $share = $this->share($owner);

        foreach (['abc', 0, -5, 2.5, [], 1000001] as $amount) {
            $this->postJson("/api/shares/{$share->id}/set-download-limit", ['amount' => $amount], $this->bearer($owner))->assertUnprocessable();
        }
        $this->assertNull($share->fresh()->download_limit);

        $this->postJson("/api/shares/{$share->id}/set-download-limit", ['amount' => 3], $this->bearer($owner))->assertOk();
        $this->assertSame(3, (int) $share->fresh()->download_limit);

        $this->postJson("/api/shares/{$share->id}/set-download-limit", ['amount' => -1], $this->bearer($owner))->assertOk();
        $this->assertNull($share->fresh()->download_limit);
    }
}
