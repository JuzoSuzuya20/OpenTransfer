<?php

namespace Tests\Feature;

use App\Jobs\backUpDatabase;
use App\Models\Setting;
use App\Models\User;
use App\Services\SettingsService;
use Carbon\Carbon;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * The backups of the database: every one the list shows can be downloaded and deleted; the
 * automatic ones follow the schedule an admin sets (or none) and go after the days chosen, while a
 * backup made by hand stays until someone deletes it.
 */
class BackupsTest extends TestCase
{
    private string $folder;

    protected function setUp(): void
    {
        parent::setUp();
        // A storage of its own: never the backups of the repository
        $storage = sys_get_temp_dir() . '/ot-backups-' . uniqid();
        File::ensureDirectoryExists($storage . '/app/backups');
        $this->app->useStoragePath($storage);
        $this->folder = storage_path('app/backups/');
        // And a database in a file of its own, outside any transaction: SQLite takes no VACUUM INTO
        // inside one, and that is how a backup is made
        touch($storage . '/database.sqlite');
        config(['database.connections.sqlite.database' => $storage . '/database.sqlite']);
        DB::purge('sqlite');
        $this->artisan('migrate:fresh', ['--seed' => true]);
    }

    protected function tearDown(): void
    {
        DB::purge('sqlite');
        File::deleteDirectory(dirname($this->folder, 2));
        parent::tearDown();
    }

    private function admin(): User
    {
        return User::factory()->create(['admin' => true, 'active' => true, 'must_change_password' => false, 'is_guest' => false]);
    }

    private function backup(string $name, int $daysOld = 0): string
    {
        $path = $this->folder . $name;
        file_put_contents($path, 'SQLite format 3');
        touch($path, now()->subDays($daysOld)->timestamp);

        return $path;
    }

    private function set(array $values): void
    {
        foreach ($values as $key => $value) {
            Setting::where('key', $key)->update(['value' => (string) $value]);
        }
        app(SettingsService::class)->clearCache();
    }

    // ----- what the list shows can be downloaded and deleted -----------------------------------

    public function test_every_backup_listed_can_be_downloaded_and_deleted(): void
    {
        $admin = $this->admin();
        $path = $this->backup('database_avant-1.5.1_2026-11-17_09-06.sqlite');

        $names = collect($this->actingAs($admin, 'api')->getJson('/api/backups')->assertOk()->json('data.backups'))->pluck('filename');
        $this->assertContains('database_avant-1.5.1_2026-11-17_09-06.sqlite', $names);

        $this->actingAs($admin, 'api')->get('/api/backups/database_avant-1.5.1_2026-11-17_09-06.sqlite/download')->assertOk();
        $this->actingAs($admin, 'api')->deleteJson('/api/backups/database_avant-1.5.1_2026-11-17_09-06.sqlite')->assertOk();
        $this->assertFileDoesNotExist($path);
    }

    public function test_nothing_outside_the_backups_is_reached(): void
    {
        $admin = $this->admin();
        file_put_contents($this->folder . 'notes.txt', 'not a backup');
        $outside = dirname($this->folder) . '/private-database.sqlite';
        file_put_contents($outside, 'the live database');
        symlink($outside, $this->folder . 'link.sqlite');

        $this->actingAs($admin, 'api')->deleteJson('/api/backups/notes.txt')->assertStatus(400);
        $this->actingAs($admin, 'api')->deleteJson('/api/backups/..sqlite')->assertStatus(400);
        $this->actingAs($admin, 'api')->deleteJson('/api/backups/link.sqlite')->assertStatus(400);
        $this->actingAs($admin, 'api')->get('/api/backups/link.sqlite/download')->assertStatus(400);

        $this->assertFileExists($this->folder . 'notes.txt');
        $this->assertFileExists($outside);
    }

    public function test_a_member_reaches_no_backup(): void
    {
        $this->backup('database_backup_2026-11-17_00-00-00.sqlite');
        $member = User::factory()->create(['admin' => false, 'active' => true]);

        $this->actingAs($member, 'api')->getJson('/api/backups')->assertForbidden();
        $this->actingAs($member, 'api')->deleteJson('/api/backups/database_backup_2026-11-17_00-00-00.sqlite')->assertForbidden();
        $this->actingAs($member, 'api')->getJson('/api/settings/backup_frequency')->assertNotFound();
    }

    // ----- by hand, automatic ------------------------------------------------------------------

    public function test_a_backup_made_by_hand_is_named_so(): void
    {
        $name = $this->actingAs($this->admin(), 'api')->postJson('/api/backups')->assertOk()->json('data.backup.filename');

        $this->assertMatchesRegularExpression('/^database_manual_\d{4}-\d{2}-\d{2}_\d{2}-\d{2}-\d{2}\.sqlite$/', $name);
        $this->assertFileExists($this->folder . $name);
    }

    public function test_only_the_automatic_backups_go_after_the_days_chosen(): void
    {
        $this->set(['backup_keep_days' => 7]);
        $oldAutomatic = $this->backup('database_backup_2026-11-01_00-00-00.sqlite', 10);
        $recentAutomatic = $this->backup('database_backup_2026-11-15_00-00-00.sqlite', 2);
        $oldByHand = $this->backup('database_manual_2026-11-01_10-00-00.sqlite', 10);
        $oldOther = $this->backup('database_avant-1.5.0_2026-11-01_14-47.sqlite', 10);

        (new backUpDatabase())->handle();

        $this->assertFileDoesNotExist($oldAutomatic);
        $this->assertFileExists($recentAutomatic);
        $this->assertFileExists($oldByHand);
        $this->assertFileExists($oldOther);
        $this->assertCount(2, glob($this->folder . 'database_backup_*.sqlite'), 'the new one and the recent one');
    }

    public function test_the_days_kept_are_the_admins(): void
    {
        $this->set(['backup_keep_days' => 30]);
        $old = $this->backup('database_backup_2026-11-01_00-00-00.sqlite', 10);

        (new backUpDatabase())->handle();

        $this->assertFileExists($old);
    }

    // ----- the schedule --------------------------------------------------------------------------

    private function backupRunsAt(string $moment): bool
    {
        Carbon::setTestNow(Carbon::parse($moment, config('app.timezone')));
        $event = collect($this->app->make(Schedule::class)->events())
            ->first(fn ($event) => str_contains((string) $event->description, 'backUpDatabase'));
        $this->assertNotNull($event, 'the backup is scheduled');

        return $event->isDue($this->app) && $event->filtersPass($this->app);
    }

    public function test_the_backup_runs_when_the_admin_set_it(): void
    {
        $this->assertTrue($this->backupRunsAt('2026-11-17 00:00'), 'every night at midnight by default');
        $this->assertFalse($this->backupRunsAt('2026-11-17 02:30'));

        $this->set(['backup_frequency' => 'weekly', 'backup_weekday' => 1, 'backup_time' => '02:30']);
        $this->assertTrue($this->backupRunsAt('2026-11-23 02:30'));
        $this->assertFalse($this->backupRunsAt('2026-11-17 00:00'));

        $this->set(['backup_frequency' => 'off']);
        $this->assertFalse($this->backupRunsAt('2026-11-23 02:30'));
        Carbon::setTestNow();
    }

    public function test_the_list_tells_when_the_next_backup_runs(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-11-17 09:00', config('app.timezone')));
        $admin = $this->admin();
        $this->set(['backup_frequency' => 'daily', 'backup_time' => '02:00']);

        $data = $this->actingAs($admin, 'api')->getJson('/api/backups')->assertOk()->json('data');
        $this->assertSame('2026-11-18 02:00', Carbon::parse($data['next_backup_at'])->setTimezone(config('app.timezone'))->format('Y-m-d H:i'));
        $this->assertSame(config('app.timezone'), $data['timezone']);

        $this->set(['backup_frequency' => 'off']);
        $this->assertNull($this->actingAs($admin, 'api')->getJson('/api/backups')->json('data.next_backup_at'));
        Carbon::setTestNow();
    }

    public function test_a_schedule_that_is_not_offered_is_refused(): void
    {
        $admin = $this->admin();
        foreach ([['backup_frequency', 'hourly'], ['backup_time', '25:00'], ['backup_weekday', '8'], ['backup_monthday', '31'], ['backup_keep_days', '0']] as [$key, $value]) {
            $this->actingAs($admin, 'api')->putJson('/api/settings', ['settings' => [['key' => $key, 'value' => $value]]])
                ->assertUnprocessable()->assertJsonPath('code', 'backup_schedule_invalid');
        }

        $this->actingAs($admin, 'api')->putJson('/api/settings', ['settings' => [
            ['key' => 'backup_frequency', 'value' => 'monthly'], ['key' => 'backup_monthday', 'value' => '28'],
            ['key' => 'backup_time', 'value' => '04:15'], ['key' => 'backup_keep_days', 'value' => '90'],
        ]])->assertOk();
        $this->assertSame('monthly', Setting::where('key', 'backup_frequency')->value('value'));
    }
}
