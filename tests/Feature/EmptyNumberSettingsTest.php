<?php

namespace Tests\Feature;

use App\Jobs\sendDeletionWarningEmails;
use App\Jobs\sendExpiryWarningEmails;
use App\Models\Setting;
use App\Models\User;
use App\Services\SettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

// The number fields of the administration can be cleared: the field then saves an empty value.
// "Longest time online" says so itself ("Empty: no limit"). An empty field means the default
// (or no limit), never every share refused, nor a scheduled job crashing every hour.
class EmptyNumberSettingsTest extends TestCase
{
    use RefreshDatabase;

    protected $seed = true;

    private function clear(string ...$keys): void
    {
        Setting::whereIn('key', $keys)->update(['value' => '']);
        app(SettingsService::class)->clearCache();
    }

    public function test_a_number_setting_reads_as_a_positive_whole_number_or_its_default(): void
    {
        $settings = app(SettingsService::class);

        foreach (['', '0', '-3', 'abc', null] as $value) {
            Setting::where('key', 'expiry_warning_days')->update(['value' => $value]);
            $settings->clearCache();
            $this->assertSame(3, $settings->positiveInt('expiry_warning_days', 3), var_export($value, true));
        }

        Setting::where('key', 'expiry_warning_days')->update(['value' => '12']);
        $settings->clearCache();
        $this->assertSame(12, $settings->positiveInt('expiry_warning_days', 3));
        $this->assertNull($settings->positiveInt('no_such_setting', null));
    }

    public function test_without_a_longest_time_a_share_may_stay_online_as_long_as_its_sender_wants(): void
    {
        $this->clear('max_expiry_time');
        $member = User::factory()->create(['admin' => false, 'active' => true, 'must_change_password' => false, 'is_guest' => false]);

        $response = $this->postJson('/api/uploads/create-share-from-uploads', [
            'upload_id' => 'upload-1',
            'name' => 'Rapport',
            'uploadIds' => ['not-uploaded'],
            'expiry_date' => now()->addDays(60)->toDateString(),
        ], ['Authorization' => 'Bearer '.auth('api')->tokenById($member->id)]);

        // Past the duration check: the send stops on the missing upload, not on its length
        $this->assertSame('Some uploads were not found or not completed', $response->json('message'));
    }

    public function test_the_warning_jobs_run_with_cleared_fields(): void
    {
        $this->clear('expiry_warning_days', 'deletion_warning_days', 'clean_files_after_days');

        (new sendExpiryWarningEmails())->handle();
        (new sendDeletionWarningEmails())->handle();

        $this->assertTrue(true, 'no job crashed');
    }
}
