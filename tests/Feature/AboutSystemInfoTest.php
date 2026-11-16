<?php

namespace Tests\Feature;

use App\Models\Setting;
use App\Models\User;
use App\Services\SettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

// The About page says what an admin can act on: the largest share the instance accepts (its
// setting, not the limit PHP sets on a single request, which uploads in pieces never meet), and
// the storage taken by the shares beside the disk they live on.
class AboutSystemInfoTest extends TestCase
{
    use RefreshDatabase;

    protected $seed = true;

    private function info()
    {
        $admin = User::factory()->create(['admin' => true, 'active' => true, 'must_change_password' => false, 'is_guest' => false]);

        return $this->actingAs($admin, 'api')->getJson('/api/stats/system-info')->assertOk();
    }

    public function test_the_largest_share_is_the_setting_of_the_instance(): void
    {
        Setting::where('key', 'max_share_size')->update(['value' => '3']);
        Setting::where('key', 'max_share_size_unit')->update(['value' => 'GB']);
        app(SettingsService::class)->clearCache();

        $this->info()->assertJsonPath('data.max_share_size.bytes', 3 * 1024 ** 3)->assertJsonMissingPath('data.max_upload_size');
    }

    public function test_without_a_limit_the_largest_share_is_none(): void
    {
        Setting::where('key', 'max_share_size')->update(['value' => '']);
        app(SettingsService::class)->clearCache();

        $this->info()->assertJsonPath('data.max_share_size.bytes', null);
    }

    public function test_the_storage_of_the_shares_and_of_the_disk_come_apart(): void
    {
        $storage = $this->info()->json('data.storage');

        foreach (['used_bytes', 'disk_total_bytes', 'disk_used_bytes', 'disk_free_bytes'] as $key) {
            $this->assertIsInt($storage[$key], $key);
        }
        $this->assertSame($storage['disk_total_bytes'], $storage['disk_used_bytes'] + $storage['disk_free_bytes']);
    }
}
