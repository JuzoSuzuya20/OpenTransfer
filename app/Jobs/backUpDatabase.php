<?php

namespace App\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class backUpDatabase implements ShouldQueue
{
    use Queueable;

    /**
     * Create a new job instance.
     */
    public function __construct()
    {
        //
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        Log::info('Backing up database');

        // Only proceed if using SQLite
        if (config('database.default') !== 'sqlite') {
            Log::info('Database backup skipped - not using SQLite');
            return;
        }

        $backupPath = storage_path('app/backups/');

        // Check the backups directory exists
        if (!file_exists($backupPath)) {
            mkdir($backupPath, 0777, true);
        }

        $backupName = 'database_backup_' . now()->format('Y-m-d_H-i-s') . '.sqlite';
        $backupFile = $backupPath . $backupName;

        try {
            DB::connection('sqlite')->statement("VACUUM INTO ?", [$backupFile]);
            Log::info('Database backup created: ' . $backupName);
        } catch (\Exception $e) {
            Log::error('Database backup failed: ' . $e->getMessage());
            return;
        }

        // The automatic backups older than the days chosen go; one made by hand stays until deleted
        $keepDays = (int) app(\App\Services\SettingsService::class)->get('backup_keep_days');
        [$min, $max] = \App\Support\BackupSchedule::KEEP_DAYS;
        $keepDays = $keepDays >= $min && $keepDays <= $max ? $keepDays : 7;
        $backups = glob($backupPath . 'database_backup_*.sqlite');
        $prunedBackups = 0;
        foreach ($backups as $backup) {
            if (filemtime($backup) < now()->subDays($keepDays)->timestamp) {
                unlink($backup);
                $prunedBackups++;
            }
        }

        Log::info('Database backups pruned: ' . $prunedBackups);
    }
}
