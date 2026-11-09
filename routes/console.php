<?php

use Illuminate\Support\Facades\Schedule;
use App\Jobs\cleanExpiredShares;
use App\Jobs\maintainDb;
use App\Jobs\sendExpiryWarningEmails;
use App\Jobs\sendExpiredWarningEmails;
use App\Jobs\sendDeletionWarningEmails;
use App\Jobs\pruneLogs;
use App\Jobs\updateLegacySharePaths;
use App\Jobs\backUpDatabase;
use App\Models\Setting;
use App\Models\User;
use App\Services\IpAllowlist;
use App\Services\StorageEncryption;
use App\Services\TwoFactor;
use App\Services\SettingsService;

//daily jobs
Schedule::job(cleanExpiredShares::class)->daily();
Schedule::job(sendExpiryWarningEmails::class)->daily();
Schedule::job(sendDeletionWarningEmails::class)->daily();
Schedule::job(maintainDb::class)->daily();
Schedule::job(pruneLogs::class)->daily();
Schedule::job(backUpDatabase::class)->daily();
//hourly jobs
Schedule::job(sendExpiredWarningEmails::class)->hourly();
//manually run jobs
Artisan::command('clean-expired-shares', function () {
    cleanExpiredShares::dispatch();
})->purpose('Clean expired shares');

Artisan::command('send-expiry-warning', function () {
    sendExpiryWarningEmails::dispatch();
})->purpose('Send expiry warning emails');

Artisan::command('send-expired-warning', function () {
    sendExpiredWarningEmails::dispatch();
})->purpose('Send expired warning emails');

Artisan::command('send-deletion-warning', function () {
    sendDeletionWarningEmails::dispatch();
})->purpose('Send deletion warning emails');

Artisan::command('maintain-db', function () {
    maintainDb::dispatch();
})->purpose('Maintain the database');

Artisan::command('prune-logs', function () {
    pruneLogs::dispatch();
})->purpose('Prune logs');

Artisan::command('update-legacy-share-paths', function () {
    updateLegacySharePaths::dispatch();
})->purpose('Update legacy share paths');

Artisan::command('back-up-database', function () {
    backUpDatabase::dispatch();
})->purpose('Back up the database');

Artisan::command('clear-settings-cache', function () {
    app(SettingsService::class)->clearCache();
    $this->info('Settings cache cleared.');
})->purpose('Clear the settings cache');

Artisan::command('disable-ip-allowlist', function () {
    Setting::where('key', IpAllowlist::SETTING_ENABLED)->update(['value' => 'false']);
    app(SettingsService::class)->clearCache();
    $this->info('The IP allowlist is disabled: sign-in is open from every network again.');
})->purpose('Turn the IP allowlist off, for an admin locked out by it');

Artisan::command('reset-2fa {email}', function (string $email) {
    $user = User::where('email', $email)->first();
    if (!$user) {
        $this->error('No user with this email.');

        return 1;
    }

    app(TwoFactor::class)->disableAll($user);
    $this->info("The second factor of {$email} is removed.");

    return 0;
})->purpose('Remove the second factor of a user who lost their device');

Artisan::command('generate-storage-key', function () {
    $this->line(StorageEncryption::generateKey());
    $this->comment('Set it as STORAGE_ENCRYPTION_KEY and keep a copy away from this server: without it, encrypted files are lost.');
})->purpose('Generate a key for STORAGE_ENCRYPTION_KEY');

// Every stored share file, whatever its state
$storedShareFiles = function (): Generator {
    $root = storage_path('app/shares');
    if (!is_dir($root)) {
        return;
    }

    $entries = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
    foreach ($entries as $entry) {
        // Shares live in sub-folders: a hidden file right here is the storage skeleton's placeholder
        $placeholder = $entry->getPath() === $root && str_starts_with($entry->getFilename(), '.');

        if ($entry->isFile() && !$entry->isLink() && !$placeholder && !str_ends_with($entry->getFilename(), '-tmp')) {
            yield $entry->getPathname();
        }
    }
};

Artisan::command('encrypt-existing-files', function () use ($storedShareFiles) {
    $encryption = app(StorageEncryption::class);
    if (!$encryption->hasKey()) {
        $this->error('STORAGE_ENCRYPTION_KEY is missing or invalid.');

        return 1;
    }

    $done = 0;
    $failed = 0;
    foreach ($storedShareFiles() as $path) {
        if (StorageEncryption::isEncrypted($path)) {
            continue;
        }
        try {
            $encryption->encryptFile($path);
            $done++;
        } catch (Throwable $e) {
            $failed++;
            $this->error($path.': '.$e->getMessage());
        }
    }

    $this->info("Encrypted {$done} file(s), {$failed} failed.");

    return $failed ? 1 : 0;
})->purpose('Encrypt the stored share files still in plain text');

Artisan::command('decrypt-files', function () use ($storedShareFiles) {
    $encryption = app(StorageEncryption::class);
    if (!$encryption->hasKey()) {
        $this->error('STORAGE_ENCRYPTION_KEY is missing or invalid.');

        return 1;
    }

    $done = 0;
    $failed = 0;
    foreach ($storedShareFiles() as $path) {
        if (!StorageEncryption::isEncrypted($path)) {
            continue;
        }
        try {
            $encryption->decryptFile($path);
            $done++;
        } catch (Throwable $e) {
            $failed++;
            $this->error($path.': '.$e->getMessage());
        }
    }

    $this->info("Decrypted {$done} file(s), {$failed} failed.");

    return $failed ? 1 : 0;
})->purpose('Decrypt every stored share file, before removing STORAGE_ENCRYPTION_KEY');
